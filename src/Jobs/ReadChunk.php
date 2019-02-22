<?php

namespace Maatwebsite\Excel\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Maatwebsite\Excel\Sheet;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use Maatwebsite\Excel\Files\TemporaryFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Maatwebsite\Excel\Filters\ChunkReadFilter;
use Maatwebsite\Excel\Imports\HeadingRowExtractor;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;

class ReadChunk implements ShouldQueue
{
    use Queueable, Dispatchable;

    /**
     * @var IReader
     */
    private $reader;

    /**
     * @var TemporaryFile
     */
    private $temporaryFile;

    /**
     * @var string
     */
    private $sheetName;

    /**
     * @var object
     */
    private $sheetImport;

    /**
     * @var int
     */
    private $startRow;

    /**
     * @var int
     */
    private $chunkSize;

    /**
     * @param IReader       $reader
     * @param TemporaryFile $temporaryFile
     * @param string        $sheetName
     * @param object        $sheetImport
     * @param int           $startRow
     * @param int           $chunkSize
     */
    public function __construct(IReader $reader, TemporaryFile $temporaryFile, string $sheetName, $sheetImport, int $startRow, int $chunkSize)
    {
        $this->reader        = $reader;
        $this->temporaryFile = $temporaryFile;
        $this->sheetName     = $sheetName;
        $this->sheetImport   = $sheetImport;
        $this->startRow      = $startRow;
        $this->chunkSize     = $chunkSize;
    }

    /**
     * @throws \Maatwebsite\Excel\Exceptions\SheetNotFoundException
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function handle()
    {
        if ($this->sheetImport instanceof WithCustomValueBinder) {
            Cell::setValueBinder($this->sheetImport);
        }

        $headingRow = HeadingRowExtractor::headingRow($this->sheetImport);

        $filter = new ChunkReadFilter(
            $headingRow,
            $this->startRow,
            $this->chunkSize,
            $this->sheetName
        );

        $this->reader->setReadFilter($filter);
        $this->reader->setReadDataOnly(true);
        $this->reader->setReadEmptyCells(false);

        $spreadsheet = $this->reader->load(
            $this->temporaryFile->fresh()->getLocalPath()
        );

        $sheet = Sheet::byName(
            $spreadsheet,
            $this->sheetName
        );

        if ($sheet->getHighestRow() < $this->startRow) {
            $sheet->disconnect();

            return;
        }

        DB::transaction(function () use ($sheet) {
            $sheet->import(
                $this->sheetImport,
                $this->startRow
            );

            $sheet->disconnect();
        });
    }
}
