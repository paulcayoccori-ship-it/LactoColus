<?php

return [
    'liquidaciones' => ['precio_litro' => '1.70', 'conflicto_tarifas' => 'bloquear', 'perdida_incluye_bonos' => null],
    'penalizaciones' => ['activo' => false],
    'ranking' => ['activo' => false, 'privacidad' => 'codigo', 'bandas' => []],
    'traslados' => ['anticipacion_dias' => null],
    'ventas' => ['precios' => ['mayorista' => '20.00', 'proveedor' => '18.00', 'publico_general' => '21.00'], 'limite_proveedor' => null, 'alcance_limite' => 'venta'],
    // Rango expresamente indicado en los requisitos del usuario, moldes por 100 L.
    'rendimiento' => ['minimo' => '11.000', 'maximo' => '12.000'],
];
