<?php

namespace IMEdge\ProcessRunner;

interface ProcessWithPidInterface
{
    public function getProcessPid(): ?int;
}
