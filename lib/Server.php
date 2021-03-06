<?php

namespace Aerys;

use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\Message;
use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;
use function Amp\Promise\all;
use function Amp\Promise\any;
use function Amp\Promise\timeout;

class Server implements Monitor {
    use CallableMaker;

    const STOPPED  = 0;
    const STARTING = 1;
    const STARTED  = 2;
    const STOPPING = 3;
    const STATES = [
        self::STOPPED => "STOPPED",
        self::STARTING => "STARTING",
        self::STARTED => "STARTED",
        self::STOPPING => "STOPPING",
    ];

    /** @var int */
    private $state = self::STOPPED;

    /** @var \Aerys\Options */
    private $options;

    /** @var \Aerys\VhostContainer */
    private $vhosts;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Aerys\Ticker */
    private $ticker;

    /** @var \SplObjectStorage */
    private $observers;

    /** @var string[] */
    private $acceptWatcherIds = [];

    /** @var resource[] Server sockets. */
    private $boundServers = [];

    /** @var resource[] */
    private $pendingTlsStreams = [];

    /** @var \Aerys\Client[] */
    private $clients = [];

    /** @var int */
    private $clientCount = 0;

    /** @var int[] */
    private $clientsPerIP = [];

    /** @var int[] */
    private $connectionTimeouts = [];

    /** @var \Aerys\NullBody */
    private $nullBody;

    /** @var \Amp\Deferred|null */
    private $stopDeferred;

    // private callables that we pass to external code //
    private $exporter;
    private $onAcceptable;
    private $onUnixSocketAcceptable;
    private $negotiateCrypto;
    private $onReadable;
    private $onWritable;
    private $onResponseDataDone;
    private $sendPreAppServiceUnavailableResponse;
    private $sendPreAppMethodNotAllowedResponse;
    private $sendPreAppInvalidHostResponse;
    private $sendPreAppTraceResponse;
    private $sendPreAppOptionsResponse;

    public function __construct(Options $options, VhostContainer $vhosts, PsrLogger $logger, Ticker $ticker) {
        $this->options = $options;
        $this->vhosts = $vhosts;
        $this->logger = $logger;
        $this->ticker = $ticker;
        $this->observers = new \SplObjectStorage;
        $this->observers->attach($ticker);
        $this->ticker->use($this->callableFromInstanceMethod("timeoutKeepAlives"));
        $this->nullBody = new NullBody;

        // private callables that we pass to external code //
        $this->exporter = $this->callableFromInstanceMethod("export");
        $this->onAcceptable = $this->callableFromInstanceMethod("onAcceptable");
        $this->onUnixSocketAcceptable = $this->callableFromInstanceMethod("onUnixSocketAcceptable");
        $this->negotiateCrypto = $this->callableFromInstanceMethod("negotiateCrypto");
        $this->onReadable = $this->callableFromInstanceMethod("onReadable");
        $this->onWritable = $this->callableFromInstanceMethod("onWritable");
        $this->onResponseDataDone = $this->callableFromInstanceMethod("onResponseDataDone");
        $this->sendPreAppServiceUnavailableResponse = $this->callableFromInstanceMethod("sendPreAppServiceUnavailableResponse");
        $this->sendPreAppMethodNotAllowedResponse = $this->callableFromInstanceMethod("sendPreAppMethodNotAllowedResponse");
        $this->sendPreAppInvalidHostResponse = $this->callableFromInstanceMethod("sendPreAppInvalidHostResponse");
        $this->sendPreAppTraceResponse = $this->callableFromInstanceMethod("sendPreAppTraceResponse");
        $this->sendPreAppOptionsResponse = $this->callableFromInstanceMethod("sendPreAppOptionsResponse");
    }

    /**
     * Retrieve the current server state.
     *
     * @return int
     */
    public function state(): int {
        return $this->state;
    }

    /**
     * Retrieve a server option value.
     *
     * @param string $option The option to retrieve
     * @throws \Error on unknown option
     */
    public function getOption(string $option) {
        return $this->options->{$option};
    }

    /**
     * Assign a server option value.
     *
     * @param string $option The option to retrieve
     * @param mixed $newValue
     * @throws \Error on unknown option
     * @return void
     */
    public function setOption(string $option, $newValue) {
        \assert($this->state < self::STARTED);
        $this->options->{$option} = $newValue;
    }

    /**
     * Attach an observer.
     *
     * @param ServerObserver $observer
     * @return void
     */
    public function attach(ServerObserver $observer) {
        $this->observers->attach($observer);
    }

    /**
     * Detach an Observer.
     *
     * @param ServerObserver $observer
     * @return void
     */
    public function detach(ServerObserver $observer) {
        $this->observers->detach($observer);
    }

    /**
     * Notify observers of a server state change.
     *
     * Resolves to an indexed any() Promise combinator array.
     *
     * @return Promise
     */
    private function notify(): Promise {
        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->update($this);
        }

        $promise = any($promises);
        $promise->onResolve(function ($error, $result) {
            // $error is always empty because an any() combinator Promise never fails.
            // Instead we check the error array at index zero in the two-item any() $result
            // and log as needed.
            list($observerErrors) = $result;
            foreach ($observerErrors as $error) {
                $this->logger->error($error);
            }
        });
        return $promise;
    }

    /**
     * Start the server.
     *
     * @param callable(array) $bindSockets is passed the $address => $context map
     * @return \Amp\Promise
     */
    public function start(callable $bindSockets = null): Promise {
        try {
            if ($this->state == self::STOPPED) {
                if ($this->vhosts->count() === 0) {
                    return new Failure(new \Error(
                        "Cannot start: no virtual hosts registered in composed VhostContainer"
                    ));
                }

                return new Coroutine($this->doStart($bindSockets ?? \Amp\coroutine(function ($addrCtxMap, $socketBinder) {
                    $serverSockets = [];
                    foreach ($addrCtxMap as $address => $context) {
                        $serverSockets[$address] = $socketBinder($address, $context);
                    }
                    return $serverSockets;
                })));
            } else {
                return new Failure(new \Error(
                    "Cannot start server: already ".self::STATES[$this->state]
                ));
            }
        } catch (\Throwable $uncaught) {
            return new Failure($uncaught);
        }
    }

    private function createHttpDriverHandlers() {
        return [
            HttpDriver::RESULT => $this->callableFromInstanceMethod("onParsedMessage"),
            HttpDriver::ENTITY_HEADERS => $this->callableFromInstanceMethod("onParsedEntityHeaders"),
            HttpDriver::ENTITY_PART => $this->callableFromInstanceMethod("onParsedEntityPart"),
            HttpDriver::ENTITY_RESULT => $this->callableFromInstanceMethod("onParsedMessageWithEntity"),
            HttpDriver::SIZE_WARNING => $this->callableFromInstanceMethod("onEntitySizeWarning"),
            HttpDriver::ERROR => $this->callableFromInstanceMethod("onParseError"),
        ];
    }

    private function doStart(callable $bindSockets): \Generator {
        assert($this->logDebug("starting"));

        $this->vhosts->setupHttpDrivers($this->createHttpDriverHandlers(), $this->callableFromInstanceMethod("writeResponse"));

        $socketBinder = function ($address, $context) {
            if (!strncmp($address, "unix://", 7)) {
                @unlink(substr($address, 7));
            }

            if (!$socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($context))) {
                throw new \RuntimeException(sprintf("Failed binding socket on %s: [Err# %s] %s", $address, $errno, $errstr));
            }

            return $socket;
        };
        $this->boundServers = yield $bindSockets($this->generateBindableAddressContextMap(), $socketBinder);

        $this->state = self::STARTING;
        $notifyResult = yield $this->notify();
        if ($hadErrors = (bool) $notifyResult[0]) {
            yield from $this->doStop();
            throw new \RuntimeException(
                "Server::STARTING observer initialization failure"
            );
        }

        /* Options now shouldn't be changed as Server has been STARTED - lock them */
        $this->options->__initialized = true;

        $this->dropPrivileges();

        $this->state = self::STARTED;
        assert($this->logDebug("started"));

        foreach ($this->boundServers as $serverName => $server) {
            $onAcceptable = $this->onAcceptable;
            if (!strncmp($serverName, "unix://", 7)) {
                $onAcceptable = $this->onUnixSocketAcceptable;
            }
            $this->acceptWatcherIds[$serverName] = Loop::onReadable($server, $onAcceptable);
            $this->logger->info("Listening on {$serverName}");
        }

        try {
            return yield $this->notify();
        } catch (\Throwable $exception) {
            yield from $this->doStop();
            throw new \RuntimeException("Server::STARTED observer initialization failure", 0, $exception);
        }
    }

    private function generateBindableAddressContextMap(): array {
        $addrCtxMap = [];
        $addresses = $this->vhosts->getBindableAddresses();
        $tlsBindings = $this->vhosts->getTlsBindingsByAddress();
        $backlogSize = $this->options->socketBacklogSize;
        $shouldReusePort = !$this->options->debug;

        foreach ($addresses as $address) {
            $context = ["socket" => [
                "backlog"      => $backlogSize,
                "so_reuseport" => $shouldReusePort,
                "so_reuseaddr" => stripos(PHP_OS, "WIN") === 0, // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                "ipv6_v6only"  => true,
            ]];
            if (isset($tlsBindings[$address])) {
                $context["ssl"] = $tlsBindings[$address];
            }
            $addrCtxMap[$address] = $context;
        }

        return $addrCtxMap;
    }

    private function onAcceptable(string $watcherId, $server) {
        if (!$client = @\stream_socket_accept($server, $timeout = 0, $peerName)) {
            return;
        }

        $portStartPos = strrpos($peerName, ":");
        $ip = substr($peerName, 0, $portStartPos);
        $port = substr($peerName, $portStartPos + 1);
        $net = @\inet_pton($ip);
        if (isset($net[4])) {
            $perIP = &$this->clientsPerIP[substr($net, 0, 7 /* /56 block */)];
        } else {
            $perIP = &$this->clientsPerIP[$net];
        }

        if (($this->clientCount++ === $this->options->maxConnections) | ($perIP++ === $this->options->connectionsPerIP)) {
            assert($this->logDebug("client denied: too many existing connections"));
            $this->clientCount--;
            $perIP--;
            @fclose($client);
            return;
        }

        \assert($this->logDebug("accept {$peerName} on " . stream_socket_get_name($client, false) . " #" . (int) $client));

        \stream_set_blocking($client, false);
        $contextOptions = \stream_context_get_options($client);
        if (isset($contextOptions["ssl"])) {
            $clientId = (int) $client;
            $watcherId = Loop::onReadable($client, $this->negotiateCrypto, [$ip, $port]);
            $this->pendingTlsStreams[$clientId] = [$watcherId, $client];
        } else {
            $this->importClient($client, $ip, $port);
        }
    }

    private function onUnixSocketAcceptable(string $watcherId, $server) {
        if (!$client = @\stream_socket_accept($server, $timeout = 0)) {
            return;
        }

        \assert($this->logDebug("accept connection on " . stream_socket_get_name($client, false) . " #" . (int) $client));

        \stream_set_blocking($client, false);
        $this->importClient($client, "", 0);
    }

    private function negotiateCrypto(string $watcherId, $socket, $peer) {
        list($ip, $port) = $peer;
        if ($handshake = @\stream_socket_enable_crypto($socket, true)) {
            $socketId = (int) $socket;
            Loop::cancel($watcherId);
            unset($this->pendingTlsStreams[$socketId]);
            assert((function () use ($socket, $ip, $port) {
                $meta = stream_get_meta_data($socket)["crypto"];
                $isH2 = (isset($meta["alpn_protocol"]) && $meta["alpn_protocol"] === "h2");
                return $this->logDebug(sprintf("crypto negotiated %s%s:%d", ($isH2 ? "(h2) " : ""), $ip, $port));
            })());
            // Dispatch via HTTP 1 driver; it knows how to handle PRI * requests - for now it is easier to dispatch only via content (ignore alpn)...
            $this->importClient($socket, $ip, $port);
        } elseif ($handshake === false) {
            assert($this->logDebug("crypto handshake error $ip:$port"));
            $this->failCryptoNegotiation($socket, $ip);
        }
    }

    private function failCryptoNegotiation($socket, $ip) {
        $this->clientCount--;
        $net = @\inet_pton($ip);
        if (isset($net[4])) {
            $net = substr($net, 0, 7 /* /56 block */);
        }
        $this->clientsPerIP[$net]--;

        $socketId = (int) $socket;
        list($watcherId) = $this->pendingTlsStreams[$socketId];
        Loop::cancel($watcherId);
        unset($this->pendingTlsStreams[$socketId]);
        @\stream_socket_shutdown($socket, \STREAM_SHUT_RDWR); // ensures a TCP FIN frame is sent even if other processes (forks) have inherited the fd
        @fclose($socket);
    }

    /**
     * Stop the server.
     *
     * @return Promise
     */
    public function stop(): Promise {
        switch ($this->state) {
            case self::STARTED:
                $stopPromise = new Coroutine($this->doStop());
                return timeout($stopPromise, $this->options->shutdownTimeout);
            case self::STOPPED:
                return new Success;
            default:
                return new Failure(new \Error(
                    "Cannot stop server: currently ".self::STATES[$this->state]
                ));
        }
    }

    private function doStop(): \Generator {
        assert($this->logDebug("stopping"));
        $this->state = self::STOPPING;

        // Unbind server sockets, otherwise connections are still sent to bound sockets but never accepted
        // In restart situation that can lead to unnecessary request errors
        foreach ($this->boundServers as $server) {
            fclose($server);
        }

        foreach ($this->acceptWatcherIds as $watcherId) {
            Loop::cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];
        foreach ($this->pendingTlsStreams as list(, $socket)) {
            $this->failCryptoNegotiation($socket, key($this->clientsPerIP) /* doesn't matter after stop */);
        }

        $this->stopDeferred = new Deferred;
        if (empty($this->clients)) {
            $this->stopDeferred->resolve();
        } else {
            foreach ($this->clients as $client) {
                if (empty($client->pendingResponses)) {
                    $this->close($client);
                } else {
                    $client->remainingRequests = 0;
                }
            }
        }

        yield all([$this->stopDeferred->promise(), $this->notify()]);

        assert($this->logDebug("stopped"));
        $this->state = self::STOPPED;
        $this->stopDeferred = null;

        yield $this->notify();
    }

    private function importClient($socket, $ip, $port) {
        $client = new Client;
        $client->id = (int) $socket;
        $client->socket = $socket;
        $client->options = $this->options;
        $client->exporter = $this->exporter;
        $client->remainingRequests = $this->options->maxRequestsPerConnection;

        $client->clientAddr = $ip;
        $client->clientPort = $port;

        $serverName = stream_socket_get_name($socket, false);
        if ($portStartPos = strrpos($serverName, ":")) {
            $client->serverAddr = substr($serverName, 0, $portStartPos);
            $client->serverPort = (int) substr($serverName, $portStartPos + 1);
        } else {
            $client->serverAddr = $serverName;
            $client->serverPort = 0;
        }

        $meta = stream_get_meta_data($socket);
        $client->cryptoInfo = $meta["crypto"] ?? [];
        $client->isEncrypted = (bool) $client->cryptoInfo;

        $client->readWatcher = Loop::onReadable($socket, $this->onReadable, $client);
        $client->writeWatcher = Loop::onWritable($socket, $this->onWritable, $client);
        Loop::disable($client->writeWatcher);

        $this->clients[$client->id] = $client;

        $client->httpDriver = $this->vhosts->selectHttpDriver($client->serverAddr, $client->serverPort);
        $client->requestParser = $client->httpDriver->parser($client);
        $client->requestParser->valid();

        $this->renewConnectionTimeout($client);
    }

    private function writeResponse(Client $client, bool $final = false) {
        $this->onWritable($client->writeWatcher, $client->socket, $client);

        if (empty($final)) {
            return;
        }

        if ($client->writeBuffer == "") {
            $this->onResponseDataDone($client);
        } else {
            $client->onWriteDrain = $this->onResponseDataDone;
        }
    }

    private function onResponseDataDone(Client $client) {
        if ($client->shouldClose || (--$client->pendingResponses == 0 && $client->isDead == Client::CLOSED_RD)) {
            $this->close($client);
        } elseif (!($client->isDead & Client::CLOSED_RD)) {
            $this->renewConnectionTimeout($client);
        }
    }

    private function onWritable(string $watcherId, $socket, Client $client) {
        $bytesWritten = @\fwrite($socket, $client->writeBuffer);
        if ($bytesWritten === false || ($bytesWritten === 0 && (!\is_resource($socket) || @\feof($socket)))) {
            if ($client->isDead == Client::CLOSED_RD) {
                $this->close($client);
            } else {
                $client->isDead = Client::CLOSED_WR;
                Loop::cancel($watcherId);
            }
        } else {
            $client->bufferSize -= $bytesWritten;
            if ($bytesWritten === \strlen($client->writeBuffer)) {
                $client->writeBuffer = "";
                Loop::disable($watcherId);
                if ($client->onWriteDrain) {
                    ($client->onWriteDrain)($client);
                }
            } else {
                $client->writeBuffer = \substr($client->writeBuffer, $bytesWritten);
                Loop::enable($watcherId);
            }
            if ($client->bufferDeferred && $client->bufferSize <= $client->options->softStreamCap) {
                $deferred = $client->bufferDeferred;
                $client->bufferDeferred = null;
                $deferred->resolve();
            }
        }
    }

    private function timeoutKeepAlives(int $now) {
        $timeouts = [];
        foreach ($this->connectionTimeouts as $id => $expiresAt) {
            if ($now > $expiresAt) {
                $timeouts[] = $this->clients[$id];
            } else {
                break;
            }
        }
        foreach ($timeouts as $client) {
            // do not close in case some longer response is taking longer, but do in case bodyEmitters aren't fulfilled
            if ($client->pendingResponses > \count($client->bodyEmitters)) {
                $this->clearConnectionTimeout($client);
            } else {
                // timeouts are only active while Client is doing nothing (not sending nor receving) and no pending writes, hence we can just fully close here
                $this->close($client);
            }
        }
    }

    private function renewConnectionTimeout(Client $client) {
        $timeoutAt = $this->ticker->currentTime + $this->options->connectionTimeout;
        // DO NOT remove the call to unset(); it looks superfluous but it's not.
        // Keep-alive timeout entries must be ordered by value. This means that
        // it's not enough to replace the existing map entry -- we have to remove
        // it completely and push it back onto the end of the array to maintain the
        // correct order.
        unset($this->connectionTimeouts[$client->id]);
        $this->connectionTimeouts[$client->id] = $timeoutAt;
    }

    private function clearConnectionTimeout(Client $client) {
        unset($this->connectionTimeouts[$client->id]);
    }

    private function onReadable(string $watcherId, $socket, Client $client) {
        $data = @\stream_get_contents($socket, $this->options->ioGranularity);
        if ($data == "") {
            if (!\is_resource($socket) || @\feof($socket)) {
                if ($client->isDead == Client::CLOSED_WR || $client->pendingResponses == 0) {
                    $this->close($client);
                } else {
                    $client->isDead = Client::CLOSED_RD;
                    Loop::cancel($watcherId);
                    if ($client->bodyEmitters) {
                        $ex = new ClientException;
                        foreach ($client->bodyEmitters as $key => $emitter) {
                            $emitter->fail($ex);
                            $client->bodyEmitters[$key] = new Emitter;
                        }
                    }
                }
            }
            return;
        }

        $this->renewConnectionTimeout($client);
        $client->requestParser->send($data);
    }

    private function onParsedMessage(InternalRequest $ireq) {
        if ($this->options->normalizeMethodCase) {
            $ireq->method = strtoupper($ireq->method);
        }

        assert($this->logDebug(sprintf(
            "%s %s HTTP/%s @ %s:%s%s",
            $ireq->method,
            $ireq->uri,
            $ireq->protocol,
            $ireq->client->clientAddr,
            $ireq->client->clientPort,
            ""//empty($parseResult["server_push"]) ? "" : " (server-push via {$parseResult["server_push"]})"
        )));

        $ireq->client->remainingRequests--;

        $ireq->time = $this->ticker->currentTime;
        $ireq->httpDate = $this->ticker->currentHttpDate;
        if (!isset($ireq->body)) {
            $ireq->body = $this->nullBody;
        }

        if (empty($ireq->headers["cookie"])) {
            $ireq->cookies = [];
        } else { // @TODO delay initialization
            $ireq->cookies = array_merge(...array_map('\Aerys\parseCookie', $ireq->headers["cookie"]));
        }

        $this->respond($ireq);
    }

    private function onParsedEntityHeaders(InternalRequest $ireq) {
        $ireq->client->bodyEmitters[$ireq->streamId] = $bodyEmitter = new Emitter;
        $ireq->body = new Message(new IteratorStream($bodyEmitter->iterate()));

        $this->onParsedMessage($ireq);
    }

    private function onParsedEntityPart(Client $client, $body, int $streamId = 0) {
        $client->bodyEmitters[$streamId]->emit($body);
    }

    private function onParsedMessageWithEntity(Client $client, int $streamId = 0) {
        $emitter = $client->bodyEmitters[$streamId];
        unset($client->bodyEmitters[$streamId]);
        $emitter->complete();
        // @TODO Update trailer headers if present

        // Don't respond() because we always start the response when headers arrive
    }

    private function onEntitySizeWarning(Client $client, int $streamId = 0) {
        $emitter = $client->bodyEmitters[$streamId];
        $client->bodyEmitters[$streamId] = new Emitter;
        $emitter->fail(new ClientSizeException);
    }

    private function onParseError(Client $client, int $status, string $message) {
        $this->clearConnectionTimeout($client);

        if ($client->bodyEmitters) {
            $client->shouldClose = true;
            $this->writeResponse($client, true);
            return;
        }

        $client->pendingResponses++;

        $ireq = new InternalRequest;
        $ireq->client = $client;
        $ireq->time = $this->ticker->currentTime;
        $ireq->httpDate = $this->ticker->currentHttpDate;

        $this->tryApplication($ireq, static function (Request $request, Response $response) use ($status, $message) {
            $body = makeGenericBody($status, [
                "msg" => $message,
            ]);
            $response->setStatus($status);
            $response->setHeader("Connection", "close");
            $response->end($body);
        }, []);
    }

    private function setTrace(InternalRequest $ireq) {
        if (\is_string($ireq->trace)) {
            $ireq->locals['aerys.trace'] = $ireq->trace;
        } else {
            $trace = "{$ireq->method} {$ireq->uri} {$ireq->protocol}\r\n";
            foreach ($ireq->trace as list($header, $value)) {
                $trace .= "$header: $value\r\n";
            }
            $ireq->locals['aerys.trace'] = $trace;
        }
    }

    private function respond(InternalRequest $ireq) {
        $ireq->client->pendingResponses++;

        if ($this->stopDeferred) {
            $this->tryApplication($ireq, $this->sendPreAppServiceUnavailableResponse, []);
        } elseif (!in_array($ireq->method, $this->options->allowedMethods)) {
            $this->tryApplication($ireq, $this->sendPreAppMethodNotAllowedResponse, []);
        } elseif (!$vhost = $this->vhosts->selectHost($ireq)) {
            $this->tryApplication($ireq, $this->sendPreAppInvalidHostResponse, []);
        } elseif ($ireq->method === "TRACE") {
            $this->setTrace($ireq);
            $this->tryApplication($ireq, $this->sendPreAppTraceResponse, []);
        } elseif ($ireq->method === "OPTIONS" && $ireq->uri === "*") {
            $this->tryApplication($ireq, $this->sendPreAppOptionsResponse, []);
        } else {
            $this->tryApplication($ireq, $vhost->getApplication(), $vhost->getFilters());
        }
    }

    private function sendPreAppServiceUnavailableResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["SERVICE_UNAVAILABLE"];
        $body = makeGenericBody($status);
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->end($body);
    }

    private function sendPreAppInvalidHostResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["BAD_REQUEST"];
        $body = makeGenericBody($status);
        $response->setStatus($status);
        $response->setReason("Bad Request: Invalid Host");
        $response->setHeader("Connection", "close");
        $response->end($body);
    }

    private function sendPreAppMethodNotAllowedResponse(Request $request, Response $response) {
        $status = HTTP_STATUS["METHOD_NOT_ALLOWED"];
        $body = makeGenericBody($status);
        $response->setStatus($status);
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", implode(", ", $this->options->allowedMethods));
        $response->end($body);
    }

    private function sendPreAppTraceResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Content-Type", "message/http");
        $response->end($request->getLocalVar('aerys.trace'));
    }

    private function sendPreAppOptionsResponse(Request $request, Response $response) {
        $response->setStatus(HTTP_STATUS["OK"]);
        $response->setHeader("Allow", implode(", ", $this->options->allowedMethods));
        $response->end();
    }

    private function tryApplication(InternalRequest $ireq, callable $application, array $filters) {
        $response = $this->initializeResponse($ireq, $filters);
        $request = new StandardRequest($ireq);

        call($application, $request, $response)->onResolve(function ($error) use ($ireq, $response, $filters) {
            if ($error) {
                if (!$error instanceof ClientException) {
                    // Ignore uncaught ClientException -- applications aren't required to catch this
                    $this->onApplicationError($error, $ireq, $response, $filters);
                }
                return;
            }

            if ($ireq->client->isExported || ($ireq->client->isDead & Client::CLOSED_WR)) {
                return;
            } elseif ($response->state() & Response::STARTED) {
                $response->end();
            } else {
                $status = HTTP_STATUS["NOT_FOUND"];
                $body = makeGenericBody($status, [
                    "sub_heading" => "Requested: {$ireq->uri}",
                ]);
                $response->setStatus($status);
                $response->end($body);
            }
        });
    }

    private function initializeResponse(InternalRequest $ireq, array $filters): Response {
        $ireq->responseWriter = $ireq->client->httpDriver->writer($ireq);
        $filters = $ireq->client->httpDriver->filters($ireq, $filters);
        if ($ireq->badFilterKeys) {
            $filters = array_diff_key($filters, array_flip($ireq->badFilterKeys));
        }
        $filter = responseFilter($filters, $ireq);
        $filter->current(); // initialize filters
        $codec = responseCodec($filter, $ireq);

        return new StandardResponse($codec, $ireq->client);
    }

    private function onApplicationError(\Throwable $error, InternalRequest $ireq, Response $response, array $filters) {
        $this->logger->error($error);

        if (($ireq->client->isDead & Client::CLOSED_WR) || $ireq->client->isExported) {
            // Responder actions may catch an initial ClientException and continue
            // doing further work. If an error arises at this point our only option
            // is to log the error (which we just did above).
            return;
        } elseif ($response->state() & Response::STARTED) {
            $this->close($ireq->client);
        } elseif (empty($ireq->filterErrorFlag)) {
            $this->tryErrorResponse($error, $ireq, $response, $filters);
        } else {
            $this->tryFilterErrorResponse($error, $ireq, $filters);
        }
    }

    /**
     * When an uncaught exception is thrown by a filter we enable the $ireq->filterErrorFlag
     * and add the offending filter's key to $ireq->badFilterKeys. Each time we initialize
     * a response the bad filters are removed from the chain in an effort to invoke all possible
     * filters. To handle the scenario where multiple filters error we need to continue looping
     * until $ireq->filterErrorFlag no longer reports as true.
     */
    private function tryFilterErrorResponse(\Throwable $error, InternalRequest $ireq, array $filters) {
        while ($ireq->filterErrorFlag) {
            try {
                $ireq->filterErrorFlag = false;
                $response = $this->initializeResponse($ireq, $filters);
                $this->tryErrorResponse($error, $ireq, $response, $filters);
            } catch (ClientException $error) {
                return;
            } catch (\Throwable $error) {
                $this->logger->error($error);
                $this->close($ireq->client);
            }
        }
    }

    private function tryErrorResponse(\Throwable $error, InternalRequest $ireq, Response $response, array $filters) {
        try {
            $status = HTTP_STATUS["INTERNAL_SERVER_ERROR"];
            $msg = ($this->options->debug) ? "<pre>" . htmlspecialchars($error) . "</pre>" : "<p>Something went wrong ...</p>";
            $body = makeGenericBody($status, [
                "sub_heading" =>"Requested: {$ireq->uri}",
                "msg" => $msg,
            ]);
            $response->setStatus(HTTP_STATUS["INTERNAL_SERVER_ERROR"]);
            $response->setHeader("Connection", "close");
            $response->end($body);
        } catch (ClientException $error) {
            return;
        } catch (\Throwable $error) {
            if ($ireq->filterErrorFlag) {
                $this->tryFilterErrorResponse($error, $ireq, $filters);
            } else {
                $this->logger->error($error);
                $this->close($ireq->client);
            }
        }
    }

    private function close(Client $client) {
        $this->clear($client);
        assert($client->isDead != Client::CLOSED_RDWR);
        @\stream_socket_shutdown($client->socket, \STREAM_SHUT_RDWR); // ensures a TCP FIN frame is sent even if other processes (forks) have inherited the fd
        @fclose($client->socket);
        $client->isDead = Client::CLOSED_RDWR;

        $this->clientCount--;
        if ($client->serverAddr[0] != "/") { // no unix domain socket
            $net = @\inet_pton($client->clientAddr);
            if (isset($net[4])) {
                $net = substr($net, 0, 7 /* /56 block */);
            }
            $this->clientsPerIP[$net]--;
            assert($this->logDebug("close {$client->clientAddr}:{$client->clientPort} #{$client->id}"));
        } else {
            assert($this->logDebug("close connection on {$client->serverAddr} #{$client->id}"));
        }

        if ($client->bodyEmitters) {
            $ex = new ClientException;
            foreach ($client->bodyEmitters as $key => $emitter) {
                $emitter->fail($ex);
                $client->bodyEmitters[$key] = new Emitter;
            }
        }
        if ($client->bufferDeferred) {
            $ex = $ex ?? new ClientException;
            $client->bufferDeferred->fail($ex);
        }
    }

    private function clear(Client $client) {
        $client->requestParser = null; // break cyclic reference
        $client->onWriteDrain = null;
        Loop::cancel($client->readWatcher);
        Loop::cancel($client->writeWatcher);
        $this->clearConnectionTimeout($client);
        unset($this->clients[$client->id]);
        if ($this->stopDeferred && empty($this->clients)) {
            $this->stopDeferred->resolve();
        }
    }

    private function export(Client $client): \Closure {
        $client->isDead = Client::CLOSED_RDWR;
        $client->isExported = true;
        $this->clear($client);

        assert($this->logDebug("export {$client->clientAddr}:{$client->clientPort}"));

        $net = @\inet_pton($client->clientAddr);
        if (isset($net[4])) {
            $net = substr($net, 0, 7 /* /56 block */);
        }
        $clientCount = &$this->clientCount;
        $clientsPerIP = &$this->clientsPerIP[$net];
        $closer = static function () use (&$clientCount, &$clientsPerIP) {
            $clientCount--;
            $clientsPerIP--;
        };
        assert($closer = (function () use ($client, &$clientCount, &$clientsPerIP) {
            $logger = $this->logger;
            $message = "close {$client->clientAddr}:{$client->clientPort}";
            return static function () use (&$clientCount, &$clientsPerIP, $logger, $message) {
                $clientCount--;
                $clientsPerIP--;
                assert($clientCount >= 0);
                assert($clientsPerIP >= 0);
                $logger->log(Logger::DEBUG, $message);
            };
        })());
        return $closer;
    }

    private function dropPrivileges() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }

        $user = $this->options->user;
        if (!extension_loaded("posix")) {
            if ($user !== null) {
                throw new \RuntimeException("Posix extension must be enabled to switch to user '{$user}'!");
            }
            $this->logger->warning("Posix extension not enabled, be sure not to run your server as root!");
        } elseif (posix_geteuid() === 0) {
            if ($user === null) {
                $this->logger->warning("Running as privileged user is discouraged! Use the 'user' option to switch to another user after startup!");
                return;
            }

            $info = posix_getpwnam($user);
            if (!$info) {
                throw new \RuntimeException("Switching to user '{$user}' failed, because it doesn't exist!");
            }

            $success = posix_seteuid($info["uid"]);
            if (!$success) {
                throw new \RuntimeException("Switching to user '{$user}' failed, probably because of missing privileges.'");
            }
        }
    }

    /**
     * This function MUST always return TRUE. It should only be invoked
     * inside an assert() block so that we can cancel its opcodes when
     * in production mode. This approach allows us to take full advantage
     * of debug mode log output without adding superfluous method call
     * overhead in production environments.
     */
    private function logDebug($message) {
        $this->logger->log(Logger::DEBUG, (string) $message);
        return true;
    }

    public function __debugInfo() {
        return [
            "state" => $this->state,
            "vhosts" => $this->vhosts,
            "ticker" => $this->ticker,
            "observers" => $this->observers,
            "acceptWatcherIds" => $this->acceptWatcherIds,
            "boundServers" => $this->boundServers,
            "pendingTlsStreams" => $this->pendingTlsStreams,
            "clients" => $this->clients,
            "connectionTimeouts" => $this->connectionTimeouts,
            "stopPromise" => $this->stopDeferred ? $this->stopDeferred->promise() : null,
        ];
    }

    public function monitor(): array {
        return [
            "state" => $this->state,
            "bindings" => $this->vhosts->getBindableAddresses(),
            "clients" => count($this->clients),
            "IPs" => count($this->clientsPerIP),
            "pendingInputs" => count($this->connectionTimeouts),
            "hosts" => $this->vhosts->monitor(),
        ];
    }
}
