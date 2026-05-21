<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SynapCores\Exceptions\SynapCoresException;
use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Console\Command;

class SynapCoresTrain extends Command
{
    protected $signature   = 'synapcores:train';
    protected $description = 'Create, train the churn_v1 experiment and score all members';

    public function __construct(private readonly SynapCoresClient $synapcores)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->step1CreateExperiment();
            $this->step2Train();
            $this->step3Predict();
        } catch (SynapCoresException $e) {
            $this->error("SynapCores error: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Training pipeline complete.');

        return self::SUCCESS;
    }

    private function step1CreateExperiment(): void
    {
        $this->info('[1/3] Creating experiment churn_v1…');

        $this->synapcores->execute(<<<SQL
            CREATE EXPERIMENT IF NOT EXISTS churn_v1
            ON loyalty_members
            WITH (
                target      = 'churned',
                model_type  = 'classification',
                features    = ['tier', 'tenure_months', 'visits_30d', 'spend_30d']
            )
        SQL);

        $this->line('     Experiment created.');
    }

    private function step2Train(): void
    {
        $this->info('[2/3] Training churn_v1 (this may take a minute)…');

        $this->synapcores->execute('TRAIN churn_v1');

        $this->line('     Training complete.');
    }

    private function step3Predict(): void
    {
        $this->info('[3/3] Scoring all members with AUTOML.PREDICT…');

        $this->synapcores->execute(<<<SQL
            UPDATE loyalty_members
            SET churn_probability = AUTOML.PREDICT(
                'churn_v1',
                tier,
                tenure_months,
                visits_30d,
                spend_30d
            )
        SQL);

        $this->line('     Predictions written to loyalty_members.churn_probability.');
    }
}
