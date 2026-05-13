<?php

namespace Crater\Console\Commands;

use Crater\Models\Company;
use Crater\Models\CompanySetting;
use Crater\Models\Estimate;
use Crater\Models\Invoice;
use Crater\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportAllPdfs extends Command
{
    protected $signature = 'crater:export-pdfs
                            {--type=all : Which records to export (all, invoices, estimates, payments)}
                            {--company= : Limit to a single company id}';

    protected $description = 'Render every invoice/estimate/payment to storage/app/invoices/{company}/...';

    public function handle(): int
    {
        $type = $this->option('type');
        $companyId = $this->option('company');

        $companies = Company::query()
            ->when($companyId, fn ($q) => $q->where('id', $companyId))
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No companies found.');
            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $this->line('');
            $this->info("Company: {$company->name} (id={$company->id})");

            $dir = 'invoices/'.Str::slug($company->name, '_').'_'.$company->id;
            Storage::disk('local')->makeDirectory($dir);

            App::setLocale(CompanySetting::getSetting('language', $company->id) ?? 'en');

            if (in_array($type, ['all', 'invoices'])) {
                $this->export(
                    Invoice::where('company_id', $company->id),
                    $dir.'/invoices',
                    fn ($r) => $r->invoice_number
                );
            }

            if (in_array($type, ['all', 'estimates'])) {
                $this->export(
                    Estimate::where('company_id', $company->id),
                    $dir.'/estimates',
                    fn ($r) => $r->estimate_number
                );
            }

            if (in_array($type, ['all', 'payments'])) {
                $this->export(
                    Payment::where('company_id', $company->id),
                    $dir.'/payments',
                    fn ($r) => $r->payment_number
                );
            }
        }

        $this->line('');
        $this->info('Done. Files in: '.storage_path('app/invoices'));
        return self::SUCCESS;
    }

    private function export($query, string $dir, \Closure $nameFn): void
    {
        $label = class_basename($query->getModel());
        $total = $query->count();

        if ($total === 0) {
            $this->line("  {$label}: none");
            return;
        }

        Storage::disk('local')->makeDirectory($dir);
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat("  {$label}: %current%/%max% [%bar%] %message%");
        $bar->setMessage('');
        $bar->start();

        $query->chunkById(50, function ($rows) use ($dir, $nameFn, $bar) {
            foreach ($rows as $row) {
                $name = $nameFn($row) ?: $row->id;
                $bar->setMessage((string) $name);
                try {
                    $pdf = $row->getPDFData();
                    Storage::disk('local')->put($dir.'/'.$name.'.pdf', $pdf->output());
                } catch (\Throwable $e) {
                    $this->line('');
                    $this->error("  Failed {$name}: ".$e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->line('');
    }
}
