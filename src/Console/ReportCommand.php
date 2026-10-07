<?php

namespace Vipertecpro\MobileEntitlements\Console;

use Illuminate\Console\Command;
use Vipertecpro\MobileEntitlements\Support\RevenueReport;

/**
 * Subscriber and sales counts per store from the entitlements table. Prints a table, or JSON with --json.
 */
class ReportCommand extends Command
{
    protected $signature = 'mobile-entitlements:report
        {--days=30 : Period in days for new subscriptions, churn, refunds and sales}
        {--json : Print JSON instead of a table}';

    protected $description = 'Report active subscribers, trials, new subscriptions, churn, refunds and sales by store';

    public function handle(RevenueReport $report): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);

        if ($days === false) {
            $this->components->error('Invalid --days value. Use a whole number from 1 to 3650.');

            return self::INVALID;
        }

        $data = $report->generate($days);

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("Last {$days} day(s), {$data['period']['from']} to {$data['period']['to']} (sandbox excluded)");

        $rows = [];

        foreach (array_keys($data['total']) as $metric) {
            $rows[] = [
                str_replace('_', ' ', ucfirst($metric)),
                $data['stores']['appStore'][$metric] ?? 0,
                $data['stores']['googlePlay'][$metric] ?? 0,
                $data['total'][$metric],
            ];
        }

        $this->table(['Metric', 'App Store', 'Google Play', 'Total'], $rows);

        return self::SUCCESS;
    }
}
