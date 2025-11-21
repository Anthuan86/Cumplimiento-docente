<?php
require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once(__DIR__.'/classes/report_analyzer.php');

use local_cumplimiento_docente\report_analyzer;

// Verificar que el usuario esté autenticado
require_login();

// Verificar permisos
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Procesar filtros
$modalidad = optional_param('modalidad', 0, PARAM_INT);
$carrera = optional_param('carrera', 0, PARAM_INT);
$nivel = optional_param('nivel', 0, PARAM_INT);
$docente = optional_param('docente', 0, PARAM_INT);

// Generar reporte
$filters = [
    'modalidad' => $modalidad,
    'carrera' => $carrera,
    'nivel' => $nivel,
    'docente' => $docente
];
$reporte = report_analyzer::generate_report($filters);

// Configurar headers para descarga de Excel
$filename = 'reporte_cumplimiento_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Crear el archivo CSV
$output = fopen('php://output', 'w');

// BOM para Excel UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados
$headers = [
    'Curso',
    'Código',
    'Modalidad',
    'Docente',
    'Email Docente',
    'Fecha Inicio',
    'Total Semanas',
    'Semanas que Cumplen',
    '% Cumplimiento',
    'Cumple Mínimo 3 Semanas',
    'Estado General'
];
fputcsv($output, $headers);

// Datos
foreach ($reporte as $item) {
    $curso = $item['curso'];
    $modalidad_nombre = $item['modalidad'];
    $analisis = $item['analisis'];

    if (!$analisis['requiere_analisis']) {
        $row = [
            format_string($curso->fullname),
            $curso->shortname,
            $modalidad_nombre,
            format_string($curso->firstname . ' ' . $curso->lastname),
            $curso->email,
            userdate($curso->startdate, '%d/%m/%Y'),
            'N/A',
            'N/A',
            'N/A',
            'N/A',
            'No requiere análisis'
        ];
    } else {
        $estado_general = 'No Cumple';
        if ($analisis['cumple_minimo'] && $analisis['porcentaje_cumplimiento'] >= 80) {
            $estado_general = 'Cumple';
        } else if ($analisis['porcentaje_cumplimiento'] >= 60) {
            $estado_general = 'Cumple Parcialmente';
        }

        $row = [
            format_string($curso->fullname),
            $curso->shortname,
            $modalidad_nombre,
            format_string($curso->firstname . ' ' . $curso->lastname),
            $curso->email,
            userdate($curso->startdate, '%d/%m/%Y'),
            $analisis['total_semanas'],
            $analisis['semanas_cumplen'],
            $analisis['porcentaje_cumplimiento'] . '%',
            $analisis['cumple_minimo'] ? 'Sí' : 'No',
            $estado_general
        ];
    }

    fputcsv($output, $row);

    // Agregar detalle por semanas
    if ($analisis['requiere_analisis'] && !empty($analisis['semanas'])) {
        fputcsv($output, []); // Línea en blanco
        fputcsv($output, ['Detalle por Semanas:']);
        fputcsv($output, ['Semana', 'Recursos Válidos', 'Tiene Recurso', 'Tiene Video', 'Cumple']);

        foreach ($analisis['semanas'] as $semana) {
            fputcsv($output, [
                $semana['nombre'],
                $semana['recursos_validos'],
                $semana['tiene_recurso'] ? 'Sí' : 'No',
                $semana['tiene_video'] ? 'Sí' : 'No',
                $semana['cumple'] ? 'Sí' : 'No'
            ]);
        }
        fputcsv($output, []); // Línea en blanco
    }
}

fclose($output);
exit;
