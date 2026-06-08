<?php

namespace Nexph\Runtime\Adaptive;

/**
 * Dynamically adjusts max accepts per tick based on runtime pressure.
 */
final class AdaptiveAcceptController
{
    private int $minAccept;
    private int $maxAccept;
    private RuntimePressureScore $scorer;

    public function __construct(
        int $minAccept = 0,
        int $maxAccept = 16,
        ?RuntimePressureScore $scorer = null
    ) {
        $this->minAccept = $minAccept;
        $this->maxAccept = $maxAccept;
        $this->scorer    = $scorer ?? new RuntimePressureScore();
    }

    /**
     * Compute accepts allowed for this tick based on current pressure.
     */
    public function acceptLimit(WorkerLocalStats $stats): int
    {
        $pressure = $this->scorer->calculate($stats);
        $stats->runtimePressureScore = $pressure;

        if ($pressure >= RuntimePressureScore::BUSY) {
            $stats->acceptPaused = true;
            return 0;
        }

        $stats->acceptPaused = false;

        if ($pressure >= RuntimePressureScore::HEALTHY) {
            // busy zone: allow 2
            return max($this->minAccept, 2);
        }

        if ($pressure >= RuntimePressureScore::IDLE) {
            // healthy zone: scale linearly between 2 and maxAccept
            $ratio = 1.0 - (($pressure - RuntimePressureScore::IDLE)
                          / (RuntimePressureScore::HEALTHY - RuntimePressureScore::IDLE));
            return max($this->minAccept, (int) round($ratio * $this->maxAccept));
        }

        // idle zone: full throttle
        return $this->maxAccept;
    }

    public function setMaxAccept(int $max): void
    {
        $this->maxAccept = max(1, $max);
    }
}
