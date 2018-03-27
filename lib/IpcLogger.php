<?php

namespace Aerys;

use Amp\Loop;

class IpcLogger extends Logger {
    use \Amp\CallableMaker;

    private $ipcSock;
    private $writeWatcherId;
    private $writeQueue = [];
    private $pendingQueue = null;
    private $writeDeferred;
    private $writeBuffer = "";
    private $isDead;

    public function __construct(Console $console, $ipcSock) {
        $this->setAnsify($console->getArg("color"));
        $level = $console->getArg("log");
        $level = isset(self::LEVELS[$level]) ? self::LEVELS[$level] : $level;
        $this->setOutputLevel($level);

        $onWritable = $this->callableFromInstanceMethod("onWritable");
        $this->ipcSock = $ipcSock;
        stream_set_blocking($ipcSock, false);
        $this->writeWatcherId = Loop::onWritable($ipcSock, $onWritable);
        Loop::disable($this->writeWatcherId);
    }

    protected function output(string $message) {
        if (empty($this->isDead)) {
            $msg = pack("N", \strlen($message)) . $message;
            if ($this->pendingQueue === null) {
                $this->writeQueue[] = $msg;
                Loop::enable($this->writeWatcherId);
            } else {
                $this->pendingQueue[] = $msg;
            }
        }
    }

    private function onWritable() {
        if ($this->isDead) {
            return;
        }

        if ($this->writeBuffer === "") {
            $this->writeBuffer = implode("", $this->writeQueue);
            $this->writeQueue = [];
        }

        $eh = set_error_handler([$this, 'onDeadIpcSock']);
        $bytes = fwrite($this->ipcSock, $this->writeBuffer);
        set_error_handler($eh);
        if ($bytes === false) {
            $this->onDeadIpcSock();
            return;
        }

        if ($bytes !== \strlen($this->writeBuffer)) {
            $this->writeBuffer = substr($this->writeBuffer, $bytes);
            return;
        }

        if ($this->writeQueue) {
            $this->writeBuffer = implode("", $this->writeQueue);
            $this->writeQueue = [];
            return;
        }

        $this->writeBuffer = "";
        Loop::disable($this->writeWatcherId);
        if ($deferred = $this->writeDeferred) {
            $this->writeDeferred = null;
            $deferred->resolve();
        }
    }

    private function onDeadIpcSock() {
        $this->isDead = true;
        $this->writeBuffer = "";
        $this->writeQueue = [];
        Loop::cancel($this->writeWatcherId);
    }

    public function flush() { // BLOCKING
        if ($this->isDead || ($this->writeBuffer === "" && empty($this->writeQueue))) {
            return;
        }

        stream_set_blocking($this->ipcSock, true);
        $this->onWritable();
        stream_set_blocking($this->ipcSock, false);
    }
}
