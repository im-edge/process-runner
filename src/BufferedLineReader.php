<?php

namespace IMEdge\ProcessRunner;

use Amp\ByteStream\WritableStream;

use function strlen;
use function strpos;
use function substr;

class BufferedLineReader implements WritableStream
{
    protected string $buffer = '';
    protected bool $writable = true;
    protected int $separatorLength;
    protected array $onClose = [];

    // protected $maxBufferSize; // Not yet. Do we need this?

    public function __construct(protected \Closure $onLine, protected string $separator)
    {
        $this->separatorLength = strlen($separator);
    }

    protected function processBuffer(): void
    {
        $lastPos = 0;
        $onLine = $this->onLine;
        while (false !== ($pos = strpos($this->buffer, $this->separator, $lastPos))) {
            $onLine(substr($this->buffer, $lastPos, $pos - $lastPos));
            $lastPos = $pos + $this->separatorLength;
        }
        if ($lastPos !== 0) {
            $this->buffer = substr($this->buffer, $lastPos);
        }
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write($bytes): void
    {
        if (! $this->writable) {
            throw new \RuntimeException('Buffered reader is not writable');
        }
        $this->buffer .= $bytes;
        if (str_contains($bytes, $this->separator)) {
            $this->processBuffer();
        }
    }

    public function end($data = null): void
    {
        if ($data !== null) {
            $this->buffer .= $data;
        }
        $this->close();
    }

    public function close(): void
    {
        $this->writable = false;
        $this->processBuffer();
        $remainingBuffer = $this->buffer;
        $this->buffer = '';
        foreach ($this->onClose as $event) {
            $event();
        }
        if ($length = strlen($remainingBuffer)) {
            throw new \Exception(sprintf(
                'There are %d unprocessed bytes in our buffer: %s',
                $length,
                substr($remainingBuffer, 0, 64)
            ));
        }
    }

    public function isClosed(): bool
    {
        return ! $this->isWritable();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose[] = $onClose;
    }
}
