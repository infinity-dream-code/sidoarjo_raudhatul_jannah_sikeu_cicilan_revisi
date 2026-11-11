<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class UploadTagihanExcelTemplateExport implements FromArray, WithEvents, WithTitle, ShouldAutoSize
{
    public function __construct(
        private string $jenis = 'spp',
    ) {
        $this->jenis = strtolower(trim($jenis)) === 'du' ? 'du' : 'spp';
    }

    public function title(): string
    {
        return $this->jenis === 'du' ? 'Template DU' : 'Template SPP';
    }

    public function array(): array
    {
        $isDu = $this->jenis === 'du';
        $cicil = $isDu ? 1 : 0;
        $keterangan = $isDu ? 'DAFTAR ULANG' : 'SPP';
        $tahun = (int) date('Y');
        $angkatan = $tahun . '/' . ($tahun + 1);

        return [
            [
                'NIS',
                'NAMA',
                'GENDER',
                'UNIT',
                'KELAS',
                'KELOMPOK',
                'ANGKATAN',
                'NO_WA',
                'EMAIL_ORTU',
                'KETERANGAN',
                'NOMINAL',
                'CICIL',
            ],
            [
                '999999991',
                'CONTOH SISWA',
                'L',
                'SMP',
                '7',
                'A',
                $angkatan,
                '081234567890',
                'ortu@example.com',
                $keterangan,
                500000,
                $cicil,
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1:L1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => $this->jenis === 'du' ? '0D6EFD' : '198754'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);
                $sheet->getStyle('A1:L2')->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'CFD4DA'],
                        ],
                    ],
                ]);
                $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('@');
                $sheet->setCellValueExplicit('A2', '999999991', DataType::TYPE_STRING);
                $sheet->getStyle('H2')->getNumberFormat()->setFormatCode('@');
                $sheet->setCellValueExplicit('H2', '081234567890', DataType::TYPE_STRING);
                $sheet->getRowDimension(1)->setRowHeight(22);
            },
        ];
    }
}
