<?php
require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once(__DIR__.'/classes/report_analyzer.php');

use local_cumplimiento_docente\report_analyzer;

// Verificar que el usuario esté autenticado
require_login();

// Verificar permisos
$context = context_system::instance();
require_capability('local/cumplimiento_docente:view', $context);

// Obtener el ID del curso
$courseid = required_param('courseid', PARAM_INT);

// Obtener datos del curso
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Obtener modalidad
$modalidad = report_analyzer::get_course_modalidad($courseid);
$modalidad_name = $modalidad ? $modalidad->modalidad_name : 'Sin modalidad';

// Obtener el análisis del curso
$analisis = report_analyzer::analyze_course_resources($courseid, $modalidad_name);

// Obtener el nombre del docente (profesor del curso)
$coursecontext = context_course::instance($courseid);
$teachers = get_enrolled_users($coursecontext, 'mod/assignment:grade', 0, 'u.id, u.firstname, u.lastname, u.email', 'u.lastname ASC', 0, 1);
$teacher_name = 'No asignado';
$teacher_email = '';
if (!empty($teachers)) {
    $teacher = reset($teachers);
    $teacher_name = format_string($teacher->firstname . ' ' . $teacher->lastname);
    $teacher_email = $teacher->email;
}

// Incluir la librería PHPSpreadsheet de Moodle
require_once($CFG->dirroot . '/lib/phpspreadsheet/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;

// Crear nuevo documento Excel
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Sistema de Cumplimiento Docente')
    ->setTitle('Informe de Cumplimiento - ' . format_string($course->fullname))
    ->setSubject('Informe de Cumplimiento Docente')
    ->setDescription('Análisis detallado del cumplimiento de requisitos del curso');

// Hoja 1: Resumen General
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Resumen General');

$row = 1;

// Título del informe
$sheet->setCellValue('A' . $row, 'INFORME DE CUMPLIMIENTO DOCENTE');
$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0066CC');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
$sheet->getRowDimension($row)->setRowHeight(30);
$row++;

// Espacio
$row++;

// Información del Curso
$sheet->setCellValue('A' . $row, 'INFORMACIÓN DEL CURSO');
$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
$row++;

$sheet->setCellValue('A' . $row, 'Nombre del Curso:');
$sheet->setCellValue('B' . $row, format_string($course->fullname));
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->mergeCells('B' . $row . ':F' . $row);
$row++;

$sheet->setCellValue('A' . $row, 'Código del Curso:');
$sheet->setCellValue('B' . $row, $course->shortname);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$row++;

$sheet->setCellValue('A' . $row, 'Docente:');
$sheet->setCellValue('B' . $row, $teacher_name);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->mergeCells('B' . $row . ':F' . $row);
$row++;

if ($teacher_email) {
    $sheet->setCellValue('A' . $row, 'Email del Docente:');
    $sheet->setCellValue('B' . $row, $teacher_email);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->mergeCells('B' . $row . ':F' . $row);
    $row++;
}

$sheet->setCellValue('A' . $row, 'Modalidad:');
$sheet->setCellValue('B' . $row, $modalidad_name);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$row++;

$sheet->setCellValue('A' . $row, 'Fecha de Inicio:');
$sheet->setCellValue('B' . $row, userdate($course->startdate, '%d/%m/%Y'));
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$row++;

if (isset($analisis['duracion_curso']) && $analisis['duracion_curso']) {
    $sheet->setCellValue('A' . $row, 'Duración del Curso:');
    $sheet->setCellValue('B' . $row, $analisis['duracion_curso'] . ' horas');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
}

$sheet->setCellValue('A' . $row, 'Fecha de Generación:');
$sheet->setCellValue('B' . $row, userdate(time(), '%d/%m/%Y %H:%M'));
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$row++;

// Espacio
$row++;

if ($analisis['requiere_analisis']) {
    // Calcular métricas generales
    $material_apoyo = $analisis['secciones']['material_apoyo'];
    $actividades = $analisis['secciones']['actividades_aprendizaje'];
    $actividades_finales = $analisis['secciones']['actividades_finales'];
    $clase_encuentro = $analisis['secciones']['clase_encuentro'];

    $total_semanas_encontradas = max(
        $material_apoyo['total_semanas'],
        $actividades['total_semanas']
    );

    $cumple_minimo_semanas = $total_semanas_encontradas >= $analisis['minimo_semanas'];

    $porcentaje_general = 0;
    $secciones_contadas = 0;

    if ($material_apoyo['encontrada']) {
        $porcentaje_general += $material_apoyo['porcentaje_cumplimiento'];
        $secciones_contadas++;
    }
    if ($actividades['encontrada']) {
        $porcentaje_general += $actividades['porcentaje_cumplimiento'];
        $secciones_contadas++;
    }
    if ($actividades_finales['encontrada']) {
        $porcentaje_finales = $actividades_finales['cumple'] ? 100 : 0;
        $porcentaje_general += $porcentaje_finales;
        $secciones_contadas++;
    }
    if ($clase_encuentro['encontrada']) {
        $porcentaje_clase = $clase_encuentro['cumple'] ? 100 : 0;
        $porcentaje_general += $porcentaje_clase;
        $secciones_contadas++;
    }

    if ($secciones_contadas > 0) {
        $porcentaje_general = round($porcentaje_general / $secciones_contadas, 2);
    }

    // RESUMEN GENERAL DE CUMPLIMIENTO
    $sheet->setCellValue('A' . $row, 'RESUMEN GENERAL DE CUMPLIMIENTO');
    $sheet->mergeCells('A' . $row . ':F' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
    $row++;

    // Tabla de resumen
    $header_row = $row;
    $sheet->setCellValue('A' . $row, 'Métrica');
    $sheet->setCellValue('B' . $row, 'Valor');
    $sheet->setCellValue('C' . $row, 'Estado');
    $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
    $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
    $row++;

    $sheet->setCellValue('A' . $row, 'Semanas Detectadas');
    $sheet->setCellValue('B' . $row, $total_semanas_encontradas);
    $sheet->setCellValue('C' . $row, $total_semanas_encontradas . ' de ' . $analisis['minimo_semanas'] . ' requeridas');
    $row++;

    $sheet->setCellValue('A' . $row, 'Cumple Mínimo de Semanas');
    $sheet->setCellValue('B' . $row, $cumple_minimo_semanas ? 'SÍ' : 'NO');
    $sheet->setCellValue('C' . $row, $cumple_minimo_semanas ? '✓ Cumple' : '✗ No Cumple');
    $color_semanas = $cumple_minimo_semanas ? 'FF92D050' : 'FFF FC7CE';
    $sheet->getStyle('B' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color_semanas);
    $row++;

    $sheet->setCellValue('A' . $row, 'Porcentaje General de Cumplimiento');
    $sheet->setCellValue('B' . $row, $porcentaje_general . '%');
    if ($porcentaje_general >= 80) {
        $estado = 'Excelente';
        $color = 'FF92D050';
    } else if ($porcentaje_general >= 60) {
        $estado = 'Aceptable';
        $color = 'FFFFFF00';
    } else {
        $estado = 'Deficiente';
        $color = 'FFFFC7CE';
    }
    $sheet->setCellValue('C' . $row, $estado);
    $sheet->getStyle('B' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
    $row++;

    // Aplicar bordes a la tabla de resumen
    $sheet->getStyle('A' . $header_row . ':C' . ($row - 1))->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

    // Espacio
    $row++;

    // DESGLOSE POR SECCIONES
    $sheet->setCellValue('A' . $row, 'DESGLOSE POR SECCIÓN');
    $sheet->mergeCells('A' . $row . ':F' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
    $row++;

    $header_row = $row;
    $sheet->setCellValue('A' . $row, 'Sección');
    $sheet->setCellValue('B' . $row, 'Encontrada');
    $sheet->setCellValue('C' . $row, 'Cumplimiento');
    $sheet->setCellValue('D' . $row, 'Estado');
    $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
    $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
    $row++;

    // Material de Apoyo
    if ($material_apoyo['encontrada']) {
        $sheet->setCellValue('A' . $row, 'Material de Apoyo');
        $sheet->setCellValue('B' . $row, 'Sí');
        $sheet->setCellValue('C' . $row, $material_apoyo['porcentaje_cumplimiento'] . '%');
        $sheet->setCellValue('D' . $row, $material_apoyo['semanas_cumplen'] . ' de ' . $material_apoyo['total_semanas'] . ' semanas');
        if ($material_apoyo['porcentaje_cumplimiento'] >= 80) {
            $sheet->getStyle('C' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF92D050');
        } else if ($material_apoyo['porcentaje_cumplimiento'] >= 60) {
            $sheet->getStyle('C' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
        } else {
            $sheet->getStyle('C' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
        }
        $row++;
    }

    // Actividades de Aprendizaje
    if ($actividades['encontrada']) {
        $sheet->setCellValue('A' . $row, 'Actividades de Aprendizaje');
        $sheet->setCellValue('B' . $row, 'Sí');
        $sheet->setCellValue('C' . $row, $actividades['porcentaje_cumplimiento'] . '%');
        $sheet->setCellValue('D' . $row, $actividades['semanas_cumplen'] . ' de ' . $actividades['total_semanas'] . ' semanas');
        if ($actividades['porcentaje_cumplimiento'] >= 80) {
            $sheet->getStyle('C' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF92D050');
        } else if ($actividades['porcentaje_cumplimiento'] >= 60) {
            $sheet->getStyle('C' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
        } else {
            $sheet->getStyle('C' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
        }
        $row++;
    }

    // Actividades Finales
    if ($actividades_finales['encontrada']) {
        $sheet->setCellValue('A' . $row, 'Actividades Finales');
        $sheet->setCellValue('B' . $row, 'Sí');
        $sheet->setCellValue('C' . $row, $actividades_finales['cumple'] ? '100%' : '0%');
        $sheet->setCellValue('D' . $row, $actividades_finales['cumple'] ? '✓ Cumple' : '✗ No Cumple');
        $color = $actividades_finales['cumple'] ? 'FF92D050' : 'FFFFC7CE';
        $sheet->getStyle('C' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
        $row++;
    }

    // CLASE-ENCUENTRO
    if ($clase_encuentro['encontrada']) {
        $sheet->setCellValue('A' . $row, 'CLASE-ENCUENTRO');
        $sheet->setCellValue('B' . $row, 'Sí');
        $sheet->setCellValue('C' . $row, $clase_encuentro['cumple'] ? '100%' : '0%');
        $sheet->setCellValue('D' . $row, $clase_encuentro['cumple'] ? '✓ Cumple' : '✗ No Cumple');
        $color = $clase_encuentro['cumple'] ? 'FF92D050' : 'FFFFC7CE';
        $sheet->getStyle('C' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
        $row++;
    }

    // Aplicar bordes
    $sheet->getStyle('A' . $header_row . ':D' . ($row - 1))->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

} else {
    $sheet->setCellValue('A' . $row, 'ESTADO DEL ANÁLISIS');
    $sheet->mergeCells('A' . $row . ':F' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
    $row++;

    $sheet->setCellValue('A' . $row, $analisis['mensaje']);
    $sheet->mergeCells('A' . $row . ':F' . $row);
    $row++;
}

// Ajustar ancho de columnas
$sheet->getColumnDimension('A')->setWidth(35);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(25);
$sheet->getColumnDimension('D')->setWidth(30);
$sheet->getColumnDimension('E')->setWidth(20);
$sheet->getColumnDimension('F')->setWidth(20);

// Crear hojas adicionales para cada sección con detalles
if ($analisis['requiere_analisis']) {
    $sheet_index = 1;

    // Hoja para Material de Apoyo
    if ($material_apoyo['encontrada'] && !empty($material_apoyo['semanas'])) {
        $sheet_index++;
        $detailSheet = $spreadsheet->createSheet($sheet_index);
        $detailSheet->setTitle('Material de Apoyo');

        $row = 1;
        $detailSheet->setCellValue('A' . $row, 'DETALLE: MATERIAL DE APOYO');
        $detailSheet->mergeCells('A' . $row . ':F' . $row);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $detailSheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $detailSheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
        $detailSheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        // Encabezados
        $detailSheet->setCellValue('A' . $row, 'Semana');
        $detailSheet->setCellValue('B' . $row, 'Recursos Válidos');
        $detailSheet->setCellValue('C' . $row, 'Mínimo Requerido');
        $detailSheet->setCellValue('D' . $row, 'Tiene Video');
        $detailSheet->setCellValue('E' . $row, 'Cumple');
        $detailSheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
        $detailSheet->getStyle('A' . $row . ':E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
        $row++;

        foreach ($material_apoyo['semanas'] as $semana) {
            $detailSheet->setCellValue('A' . $row, $semana['nombre']);
            $detailSheet->setCellValue('B' . $row, $semana['recursos_docente_validos']);
            $detailSheet->setCellValue('C' . $row, $analisis['minimo_recursos_por_semana']);
            $detailSheet->setCellValue('D' . $row, $semana['tiene_video'] ? 'Sí' : 'No');
            $detailSheet->setCellValue('E' . $row, $semana['cumple'] ? 'SÍ' : 'NO');

            $color = $semana['cumple'] ? 'FF92D050' : 'FFFFC7CE';
            $detailSheet->getStyle('E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
            $row++;
        }

        $detailSheet->getColumnDimension('A')->setWidth(20);
        $detailSheet->getColumnDimension('B')->setWidth(20);
        $detailSheet->getColumnDimension('C')->setWidth(20);
        $detailSheet->getColumnDimension('D')->setWidth(15);
        $detailSheet->getColumnDimension('E')->setWidth(15);
    }

    // Hoja para Actividades de Aprendizaje
    if ($actividades['encontrada'] && !empty($actividades['semanas'])) {
        $sheet_index++;
        $detailSheet = $spreadsheet->createSheet($sheet_index);
        $detailSheet->setTitle('Activ. Aprendizaje');

        $row = 1;
        $detailSheet->setCellValue('A' . $row, 'DETALLE: ACTIVIDADES DE APRENDIZAJE');
        $detailSheet->mergeCells('A' . $row . ':F' . $row);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $detailSheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $detailSheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
        $detailSheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        // Encabezados
        $detailSheet->setCellValue('A' . $row, 'Semana');
        $detailSheet->setCellValue('B' . $row, 'Actividades Válidas');
        $detailSheet->setCellValue('C' . $row, 'Mínimo Requerido');
        $detailSheet->setCellValue('D' . $row, 'Total Actividades');
        $detailSheet->setCellValue('E' . $row, 'Cumple');
        $detailSheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
        $detailSheet->getStyle('A' . $row . ':E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
        $row++;

        foreach ($actividades['semanas'] as $semana) {
            $detailSheet->setCellValue('A' . $row, $semana['semana']);
            $detailSheet->setCellValue('B' . $row, $semana['actividades_validas']);
            $detailSheet->setCellValue('C' . $row, $analisis['minimo_actividades_por_semana']);
            $detailSheet->setCellValue('D' . $row, $semana['total_actividades']);
            $detailSheet->setCellValue('E' . $row, $semana['cumple'] ? 'SÍ' : 'NO');

            $color = $semana['cumple'] ? 'FF92D050' : 'FFFFC7CE';
            $detailSheet->getStyle('E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
            $row++;
        }

        $detailSheet->getColumnDimension('A')->setWidth(20);
        $detailSheet->getColumnDimension('B')->setWidth(20);
        $detailSheet->getColumnDimension('C')->setWidth(20);
        $detailSheet->getColumnDimension('D')->setWidth(20);
        $detailSheet->getColumnDimension('E')->setWidth(15);
    }

    // Hoja para Actividades Finales
    if ($actividades_finales['encontrada']) {
        $sheet_index++;
        $detailSheet = $spreadsheet->createSheet($sheet_index);
        $detailSheet->setTitle('Activ. Finales');

        $row = 1;
        $detailSheet->setCellValue('A' . $row, 'DETALLE: ACTIVIDADES FINALES');
        $detailSheet->mergeCells('A' . $row . ':F' . $row);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $detailSheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $detailSheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
        $detailSheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        // Estado general
        $detailSheet->setCellValue('A' . $row, 'Estado de Cumplimiento:');
        $detailSheet->setCellValue('B' . $row, $actividades_finales['cumple'] ? 'CUMPLE' : 'NO CUMPLE');
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $detailSheet->getStyle('B' . $row)->getFont()->setBold(true);
        $color = $actividades_finales['cumple'] ? 'FF92D050' : 'FFFFC7CE';
        $detailSheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
        $row += 2;

        // Información de modalidad
        $detailSheet->setCellValue('A' . $row, 'Modalidad:');
        $detailSheet->setCellValue('B' . $row, $modalidad_name);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $detailSheet->setCellValue('A' . $row, 'Mensaje:');
        $detailSheet->setCellValue('B' . $row, $actividades_finales['mensaje']);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $detailSheet->mergeCells('B' . $row . ':F' . $row);
        $detailSheet->getStyle('B' . $row)->getAlignment()->setWrapText(true);
        $row += 2;

        // Actividades encontradas
        if (!empty($actividades_finales['actividades'])) {
            $detailSheet->setCellValue('A' . $row, 'ACTIVIDADES ENCONTRADAS');
            $detailSheet->mergeCells('A' . $row . ':F' . $row);
            $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
            $detailSheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
            $row++;

            foreach ($actividades_finales['actividades'] as $actividad) {
                $detailSheet->setCellValue('A' . $row, 'Tipo:');
                $detailSheet->setCellValue('B' . $row, ucfirst($actividad['tipo']));
                $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
                $row++;

                $detailSheet->setCellValue('A' . $row, 'Nombre:');
                $detailSheet->setCellValue('B' . $row, $actividad['nombre']);
                $detailSheet->mergeCells('B' . $row . ':F' . $row);
                $row++;

                $detailSheet->setCellValue('A' . $row, 'Fecha de Edición:');
                $detailSheet->setCellValue('B' . $row, userdate($actividad['fecha'], '%d/%m/%Y %H:%M'));
                $row++;

                if ($actividad['tipo'] === 'quiz') {
                    $detailSheet->setCellValue('A' . $row, 'Total de Preguntas:');
                    $detailSheet->setCellValue('B' . $row, $actividad['detalles']['total_preguntas']);
                    $cumple_preguntas = $actividad['detalles']['cumple_preguntas'];
                    $detailSheet->setCellValue('C' . $row, $cumple_preguntas ? '✓ Cumple (≥30)' : '✗ No cumple (<30)');
                    $color = $cumple_preguntas ? 'FF92D050' : 'FFFFC7CE';
                    $detailSheet->getStyle('C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
                    $row++;

                    $detailSheet->setCellValue('A' . $row, 'Nombre Válido:');
                    $nombre_valido = $actividad['detalles']['nombre_valido'];
                    $detailSheet->setCellValue('B' . $row, $nombre_valido ? 'Sí' : 'No');
                    $color = $nombre_valido ? 'FF92D050' : 'FFFFC7CE';
                    $detailSheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
                    $row++;

                    $detailSheet->setCellValue('A' . $row, 'Tiene Intentos:');
                    $tiene_intentos = $actividad['detalles']['tiene_intentos'];
                    $detailSheet->setCellValue('B' . $row, $tiene_intentos ? 'Sí' : 'No');
                    $color = $tiene_intentos ? 'FF92D050' : 'FFFFC7CE';
                    $detailSheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
                    $row++;
                } else if ($actividad['tipo'] === 'assign') {
                    $detailSheet->setCellValue('A' . $row, 'Nombre Esperado:');
                    $detailSheet->setCellValue('B' . $row, $actividad['detalles']['nombre_esperado']);
                    $row++;

                    $detailSheet->setCellValue('A' . $row, 'Nombre Válido:');
                    $nombre_valido = $actividad['detalles']['nombre_valido'];
                    $detailSheet->setCellValue('B' . $row, $nombre_valido ? 'Sí' : 'No');
                    $color = $nombre_valido ? 'FF92D050' : 'FFFFC7CE';
                    $detailSheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
                    $row++;

                    $detailSheet->setCellValue('A' . $row, 'Entrega Activada:');
                    $tiene_entregas = $actividad['detalles']['tiene_entregas'];
                    $detailSheet->setCellValue('B' . $row, $tiene_entregas ? 'Sí' : 'No');
                    $color = $tiene_entregas ? 'FF92D050' : 'FFFFC7CE';
                    $detailSheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
                    $row++;
                }

                $detailSheet->setCellValue('A' . $row, 'Es Válida:');
                $es_valida = $actividad['detalles']['es_valido'];
                $detailSheet->setCellValue('B' . $row, $es_valida ? 'SÍ' : 'NO');
                $detailSheet->getStyle('B' . $row)->getFont()->setBold(true);
                $color = $es_valida ? 'FF92D050' : 'FFFFC7CE';
                $detailSheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
                $row += 2;
            }
        }

        $detailSheet->getColumnDimension('A')->setWidth(25);
        $detailSheet->getColumnDimension('B')->setWidth(30);
        $detailSheet->getColumnDimension('C')->setWidth(25);
        $detailSheet->getColumnDimension('D')->setWidth(20);
        $detailSheet->getColumnDimension('E')->setWidth(20);
        $detailSheet->getColumnDimension('F')->setWidth(20);
    }

    // Hoja para CLASE-ENCUENTRO
    if ($clase_encuentro['encontrada']) {
        $sheet_index++;
        $detailSheet = $spreadsheet->createSheet($sheet_index);
        $detailSheet->setTitle('CLASE-ENCUENTRO');

        $row = 1;
        $detailSheet->setCellValue('A' . $row, 'DETALLE: CLASE-ENCUENTRO');
        $detailSheet->mergeCells('A' . $row . ':F' . $row);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $detailSheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $detailSheet->getStyle('A' . $row)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
        $detailSheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        // Estado general
        $detailSheet->setCellValue('A' . $row, 'Estado de Cumplimiento:');
        $detailSheet->setCellValue('B' . $row, $clase_encuentro['cumple'] ? 'CUMPLE' : 'NO CUMPLE');
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $detailSheet->getStyle('B' . $row)->getFont()->setBold(true);
        $color = $clase_encuentro['cumple'] ? 'FF92D050' : 'FFFFC7CE';
        $detailSheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
        $row += 2;

        $detailSheet->setCellValue('A' . $row, 'Mensaje:');
        $detailSheet->setCellValue('B' . $row, $clase_encuentro['mensaje']);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $detailSheet->mergeCells('B' . $row . ':F' . $row);
        $detailSheet->getStyle('B' . $row)->getAlignment()->setWrapText(true);
        $row += 2;

        // Herramientas LTI encontradas
        if (!empty($clase_encuentro['herramientas_lti'])) {
            $detailSheet->setCellValue('A' . $row, 'HERRAMIENTAS LTI ENCONTRADAS');
            $detailSheet->mergeCells('A' . $row . ':F' . $row);
            $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
            $detailSheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
            $row++;

            foreach ($clase_encuentro['herramientas_lti'] as $herramienta) {
                $detailSheet->setCellValue('A' . $row, 'Nombre:');
                $detailSheet->setCellValue('B' . $row, $herramienta['nombre']);
                $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
                $detailSheet->mergeCells('B' . $row . ':F' . $row);
                $row++;

                $detailSheet->setCellValue('A' . $row, 'Tipo:');
                $detailSheet->setCellValue('B' . $row, $herramienta['tipo']);
                $row++;

                $detailSheet->setCellValue('A' . $row, 'Fecha de Edición:');
                $detailSheet->setCellValue('B' . $row, userdate($herramienta['fecha'], '%d/%m/%Y %H:%M'));
                $row++;

                $detailSheet->setCellValue('A' . $row, 'Total de Accesos:');
                $detailSheet->setCellValue('B' . $row, $herramienta['total_accesos']);
                $row++;

                $detailSheet->setCellValue('A' . $row, 'Usuarios Únicos:');
                $detailSheet->setCellValue('B' . $row, $herramienta['usuarios_unicos']);
                $row++;

                $detailSheet->setCellValue('A' . $row, 'Eventos de Creación:');
                $detailSheet->setCellValue('B' . $row, $herramienta['eventos_creacion']);
                $row++;

                $detailSheet->setCellValue('A' . $row, 'Eventos de Actualización:');
                $detailSheet->setCellValue('B' . $row, $herramienta['eventos_actualizacion']);
                $row++;

                // Análisis de eventos
                if (!empty($herramienta['log_eventos'])) {
                    $detailSheet->setCellValue('A' . $row, 'Detalle de Eventos:');
                    $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
                    $row++;

                    // Encabezados de tabla de eventos
                    $detailSheet->setCellValue('B' . $row, 'Tipo de Evento');
                    $detailSheet->setCellValue('C' . $row, 'Cantidad');
                    $detailSheet->getStyle('B' . $row . ':C' . $row)->getFont()->setBold(true);
                    $detailSheet->getStyle('B' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE7E6E6');
                    $row++;

                    foreach ($herramienta['log_eventos'] as $evento) {
                        $detailSheet->setCellValue('B' . $row, $evento->action);
                        $detailSheet->setCellValue('C' . $row, $evento->cantidad);
                        $row++;
                    }
                }

                $row += 2; // Espacio entre herramientas
            }
        }

        // Estadísticas generales
        $row++;
        $detailSheet->setCellValue('A' . $row, 'ESTADÍSTICAS GENERALES');
        $detailSheet->mergeCells('A' . $row . ':F' . $row);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $detailSheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
        $row++;

        $detailSheet->setCellValue('A' . $row, 'Total de Herramientas LTI:');
        $detailSheet->setCellValue('B' . $row, count($clase_encuentro['herramientas_lti']));
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $detailSheet->setCellValue('A' . $row, 'Total de Accesos (todas las herramientas):');
        $detailSheet->setCellValue('B' . $row, $clase_encuentro['total_accesos']);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $detailSheet->setCellValue('A' . $row, 'Total de Usuarios Únicos:');
        $detailSheet->setCellValue('B' . $row, $clase_encuentro['usuarios_unicos']);
        $detailSheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $detailSheet->getColumnDimension('A')->setWidth(35);
        $detailSheet->getColumnDimension('B')->setWidth(30);
        $detailSheet->getColumnDimension('C')->setWidth(20);
        $detailSheet->getColumnDimension('D')->setWidth(20);
        $detailSheet->getColumnDimension('E')->setWidth(20);
        $detailSheet->getColumnDimension('F')->setWidth(20);
    }
}

// Generar el archivo Excel
$spreadsheet->setActiveSheetIndex(0);

$filename = 'Informe_Cumplimiento_' . clean_filename($course->shortname) . '_' . date('Y-m-d') . '.xlsx';

// Enviar headers
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
