<?php

namespace IMEdge\ProcessRunner;

use Amp\DeferredFuture;
use Amp\Process\Process;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use RuntimeException;

use function Amp\Future\await;

abstract class ProcessRunnerHelper implements ProcessWithPidInterface
{
    protected string $applicationName = 'Application';
    protected ?Process $process = null;
    protected string $baseDir;
    protected string $binary;
    protected LoggerInterface $logger;
    protected ?int $pid;

    public function __construct(string $binary, string $baseDir, LoggerInterface $logger)
    {
        if (! is_executable($binary)) {
            throw new RuntimeException("Cannot execute $binary");
        }
        $this->binary = $binary;
        $this->baseDir = $baseDir;
        $this->logger = $logger;
        $this->initialize();
    }

    protected function initialize(): void
    {
    }

    public function run(): void
    {
        $this->onStartingProcess();
        // setsid avoids INT and other signals trickling down
        $process = Process::start(
            array_merge(['setsid', $this->getBinary()], $this->getArguments()),
            $this->getWorkingDirectory(),
            $this->getEnv()
        );
        $this->process = $process;
        $this->pid = $process->getPid();
        // TODO: here we might want to restart the process, if terminated
        // Missing: information related to exit code
        $this->onProcessStarted($process);
    }

    public function getWorkingDirectory(): string
    {
        return $this->baseDir;
    }

    /**
     * @return array<string, string>
     */
    public function getEnv(): array
    {
        return getenv();
    }

    public function stop(): void
    {
        if (! $this->process) {
            return;
        }
        $deferred = new DeferredFuture();
        $this->logger->notice(sprintf('Stopping %s (PID %s)', $this->applicationName, $this->pid));
        $this->process->signal(SIGTERM);
        $attempts = 0;
        $timer = EventLoop::repeat(0.1, function () use ($deferred, &$timer, &$attempts) {
            if (! $this->process?->isRunning()) {
                $deferred->complete();
                if ($timer) {
                    EventLoop::cancel($timer);
                }
                $timer = null;
                $this->process = null;
            }
            $attempts++;
            if ($attempts > 50) {
                $this->logger->warning(sprintf(
                    '%s did not stop in time, killing the process',
                    $this->applicationName
                ));
                $this->process?->kill();
                $this->process = null;
                if ($timer) {
                    EventLoop::cancel($timer);
                }
                $timer = null;
            }
        });
        await([$deferred->getFuture()]);
    }

    public function getProcessPid(): ?int
    {
        return $this->pid;
    }

    protected function getBinary(): string
    {
        return $this->binary;
    }

    /**
     * @return string[]
     */
    protected function getArguments(): array
    {
        return [];
    }

    protected function onStartingProcess(): void
    {
    }

    protected function onProcessStarted(Process $process): void
    {
    }
}
