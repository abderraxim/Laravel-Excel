<?php

namespace Maatwebsite\Excel;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Jobs\ReadChunk;
use Maatwebsite\Excel\Jobs\QueueImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ChunkReader
{
    /**
     * @param WithChunkReading $import
     * @param IReader          $reader
     * @param string           $file
     *
     * @return \Illuminate\Foundation\Bus\PendingDispatch|null
     */
    public function read(WithChunkReading $import, IReader $reader, string $file)
    {
        $chunkSize  = $import->chunkSize();
        $totalRows  = $this->getTotalRows($reader, $file);
        $worksheets = $this->getWorksheets($import, $reader, $file);

        $jobs = new Collection();
        foreach ($worksheets as $name => $sheetImport) {
            for ($startRow = 1; $startRow <= $totalRows[$name]; $startRow += $chunkSize) {
                $jobs->push(new ReadChunk(
                    $reader,
                    $file,
                    $name,
                    $sheetImport,
                    $startRow,
                    $chunkSize
                ));
            }
        }

        if ($import instanceof ShouldQueue) {
            return QueueImport::withChain($jobs->toArray())->dispatch();
        }

        $jobs->each(function (ReadChunk $job) {
            dispatch_now($job);
        });
    }

    /**
     * @param WithChunkReading $import
     * @param IReader          $reader
     * @param string           $file
     *
     * @return array
     */
    private function getWorksheets(WithChunkReading $import, IReader $reader, string $file): array
    {
        if (!method_exists($reader, 'listWorksheetNames')) {
            return ['Worksheet' => $import];
        }

        $worksheets     = [];
        $worksheetNames = $reader->listWorksheetNames($file);
        if ($import instanceof WithMultipleSheets) {
            $sheetImports = $import->sheets();

            // Load specific sheets.
            if (method_exists($reader, 'setLoadSheetsOnly')) {
                $reader->setLoadSheetsOnly(array_keys($sheetImports));
            }

            foreach ($sheetImports as $index => $sheetImport) {
                if (is_numeric($index)) {
                    $index = $worksheetNames[$index] ?? $index;
                }

                // Specify with worksheet name should have which import.
                $worksheets[$index] = $sheetImport;
            }
        } else {
            // Each worksheet the same import class.
            foreach ($worksheetNames as $name) {
                $worksheets[$name] = $import;
            }
        }

        return $worksheets;
    }

    /**
     * @param IReader $reader
     * @param string  $file
     *
     * @return array
     */
    private function getTotalRows(IReader $reader, string $file): array
    {
        $info = $reader->listWorksheetInfo($file);

        $totalRows = [];
        foreach ($info as $sheet) {
            $totalRows[$sheet['worksheetName']] = $sheet['totalRows'];
        }

        return $totalRows;
    }
}
