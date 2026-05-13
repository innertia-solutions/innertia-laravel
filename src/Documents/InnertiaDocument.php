<?php

namespace Innertia\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for generating app-specific PDF documents (invoices, reports, contracts…).
 *
 * Usage:
 *
 *   class InvoicePdf extends InnertiaDocument
 *   {
 *       public function __construct(private Invoice $invoice) {}
 *
 *       public function view(): string { return 'pdfs.invoice'; }
 *       public function data(): array  { return ['invoice' => $this->invoice]; }
 *   }
 *
 *   // In controller:
 *   return (new InvoicePdf($invoice))->download('factura-123.pdf');
 *   return (new InvoicePdf($invoice))->stream();
 *   return (new InvoicePdf($invoice))->store(disk: 's3', path: "invoices/{$invoice->id}.pdf");
 */
abstract class InnertiaDocument
{
    /** Blade view path that renders the document HTML. */
    abstract public function view(): string;

    /** Data passed to the Blade view. */
    abstract public function data(): array;

    /** Paper size. Override to change. */
    public function paper(): string
    {
        return 'A4';
    }

    /** Paper orientation. Override to change. */
    public function orientation(): string
    {
        return 'portrait';
    }

    // ── Delivery ──────────────────────────────────────────────────────────────

    /**
     * Force-download the PDF.
     */
    public function download(string $filename): Response
    {
        return response($this->render(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Stream the PDF inline (opens in browser).
     */
    public function stream(string $filename = 'document.pdf'): Response
    {
        return response($this->render(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Store the PDF to a storage disk and return the stored path.
     *
     * @param  string  $path  e.g. "invoices/2025/inv-123.pdf"
     * @param  string  $disk  Storage disk name (defaults to default disk)
     */
    public function store(string $path, string $disk = ''): string
    {
        $disk = $disk ?: config('filesystems.default', 'local');

        Storage::disk($disk)->put($path, $this->render());

        return $path;
    }

    /**
     * Get the raw PDF binary string.
     */
    public function render(): string
    {
        $html = View::make($this->view(), $this->data())->render();

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($this->paper(), $this->orientation());
        $dompdf->render();

        return $dompdf->output();
    }
}
