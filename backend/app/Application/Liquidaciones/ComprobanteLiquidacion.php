<?php

namespace App\Application\Liquidaciones;

use App\Infrastructure\Liquidaciones\Liquidacion;

final class ComprobanteLiquidacion
{
    public function lines(Liquidacion $l): array
    {
        $totals = app(GestionarLiquidaciones::class)->totals($l);
        $lines = ['COMPROBANTE DE LIQUIDACION SEMANAL', $l->uuid, 'Productor: '.implode(' ', $l->productor_snapshot), 'Periodo: '.$l->periodo->desde->format('d/m/Y').' al '.$l->periodo->hasta->format('d/m/Y'), 'Pago previsto: '.$l->periodo->pago_previsto->format('d/m/Y'), 'Estado: '.$l->estado, ''];
        foreach ($l->litros_diarios as $day => $liters) {
            $lines[] = $day.'     '.$liters.' litros';
        }
        $lines = array_merge($lines, ['Total litros: '.$l->litros_total, 'Precio base por litro: S/ '.$l->precio_litro, 'Importe bruto: S/ '.$l->importe_bruto, 'Penalizaciones: S/ '.$l->penalizaciones, 'Compras de queso: S/ '.$l->descuentos_queso, 'Bonos aprobados: S/ '.$totals['bonos'], 'Bonos retenidos: S/ '.$totals['bonos_retenidos'], 'Ajustes aprobados: S/ '.$totals['ajustes'], 'TOTAL: S/ '.$totals['total'], 'Version de tarifa: '.$l->regla_aplicada['version'], '']);
        foreach ($l->detalle_calculo['sanciones'] ?? [] as $s) {
            $lines[] = 'Sanción: '.$s['uuid'].' / '.$s['tipo'].' / tarifa '.($s['tarifa_penalizada'] ?? 'sin tarifa').' / pérdida '.($s['decision_perdida'] ?? 'no aplica');
        }
        foreach ($l->ajustes as $a) {
            $lines[] = 'Ajuste '.$a->tipo.' S/ '.$a->importe.' ('.$a->estado.'): '.$a->motivo;
        }
        if ($l->pago) {
            $lines[] = 'Pago: '.$l->pago->pagado_at->format('d/m/Y H:i').' / '.$l->pago->metodo.' / S/ '.$l->pago->importe;
            $lines[] = 'Referencia: '.$l->pago->uuid_externo;
        }
        $lines[] = 'Documento interno de LactoColus. No constituye comprobante tributario.';

        return $lines;
    }

    public function pdf(Liquidacion $l): string
    {
        $lines = [];
        foreach ($this->lines($l) as $line) {
            foreach (explode("\n", wordwrap($line, 88, "\n", true)) as $wrapped) {
                $lines[] = $wrapped;
            }
        }
        $pages = array_chunk($lines, 44);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $index => $page) {
            $pageId = 4 + $index * 2;
            $streamId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $content = "q 0.38 0.24 0.78 rg 40 793 m 52 800 l 64 793 l 52 786 l h f 0.55 0.40 0.88 rg 40 791 m 51 784 l 51 771 l 40 778 l h f 0.29 0.16 0.62 rg 53 784 m 64 791 l 64 778 l 53 771 l h f Q\nBT /F1 19 Tf 76 781 Td (LactoColus) Tj ET\nBT /F1 10 Tf 40 750 Td 15 TL\n";
            foreach ($page as $line) {
                $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
                $encoded = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
                $content .= '('.$encoded.") Tj T*\n";
            }
            $content .= "ET\nBT /F1 9 Tf 40 35 Td (Pagina ".($index + 1).' de '.count($pages).") Tj ET\n";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$streamId.' 0 R >>';
            $objects[$streamId] = '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ', $kids).'] >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        } $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($objects as $id => $object) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
