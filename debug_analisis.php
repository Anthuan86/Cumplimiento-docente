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

// Verificar que el curso existe
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Configurar la página
$PAGE->set_url(new moodle_url('/local/cumplimiento_docente/debug_analisis.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Debug: Análisis Completo - ' . format_string($course->fullname));
$PAGE->set_heading('Debug: Análisis Completo');
$PAGE->set_pagelayout('admin');

// Obtener modalidad
$modalidad = report_analyzer::get_course_modalidad($courseid);
$modalidad_name = $modalidad ? $modalidad->modalidad_name : 'Sin modalidad';

// Generar análisis
$analisis = report_analyzer::analyze_course_resources($courseid, $modalidad_name);

echo $OUTPUT->header();

?>

<div class="debug-analisis-container">
    <div class="alert alert-warning">
        <strong>⚠️ Modo Diagnóstico:</strong> Esta página muestra el resultado del análisis tal como lo procesa el sistema.
    </div>

    <h3>Curso: <?php echo format_string($course->fullname); ?></h3>
    <p><strong>Modalidad:</strong> <?php echo $modalidad_name; ?></p>

    <?php if ($analisis['requiere_analisis']): ?>

        <h4>Estructura del Análisis:</h4>

        <?php
        $secciones_a_mostrar = [
            'material_apoyo' => 'Recursos o Material de Apoyo',
            'actividades_aprendizaje' => 'Actividades de Aprendizaje'
        ];

        foreach ($secciones_a_mostrar as $seccion_key => $seccion_titulo):
            $seccion = $analisis['secciones'][$seccion_key];
        ?>
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5><?php echo $seccion_titulo; ?></h5>
                </div>
                <div class="card-body">
                    <?php if ($seccion['encontrada']): ?>
                        <p><strong>Sección encontrada:</strong> <?php echo format_string($seccion['nombre']); ?></p>
                        <p><strong>Total semanas detectadas:</strong> <?php echo $seccion['total_semanas']; ?></p>

                        <h6>Listado de semanas:</h6>
                        <table class="table table-bordered table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Número de Semana</th>
                                    <th>Nombre</th>
                                    <th>Total Recursos</th>
                                    <th>Recursos Válidos</th>
                                    <th>Tiene Video</th>
                                    <th>Cumple</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $contador = 1;
                                foreach ($seccion['semanas'] as $semana):
                                ?>
                                    <tr class="<?php echo $semana['cumple'] ? 'table-success' : 'table-danger'; ?>">
                                        <td><?php echo $contador; ?></td>
                                        <td><strong><?php echo $semana['semana']; ?></strong></td>
                                        <td><?php echo $semana['nombre']; ?></td>
                                        <td><?php echo count($semana['recursos']); ?></td>
                                        <td><?php echo $semana['recursos_docente_validos']; ?></td>
                                        <td><?php echo $semana['tiene_video'] ? '✓' : '✗'; ?></td>
                                        <td><?php echo $semana['cumple'] ? '✓' : '✗'; ?></td>
                                    </tr>
                                <?php
                                    $contador++;
                                endforeach;
                                ?>
                            </tbody>
                        </table>

                        <h6>Debug Raw Array:</h6>
                        <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px; font-size: 11px;"><?php
                        // Mostrar solo los números de semana para debug
                        $semanas_numeros = array_map(function($s) { return $s['semana']; }, $seccion['semanas']);
                        echo "Números de semana en el array: " . implode(', ', $semanas_numeros) . "\n\n";
                        echo "Total elementos en array: " . count($seccion['semanas']) . "\n";
                        echo "Total semanas únicas: " . count(array_unique($semanas_numeros)) . "\n\n";

                        // Detectar duplicados
                        $duplicados = array_diff_assoc($semanas_numeros, array_unique($semanas_numeros));
                        if (!empty($duplicados)) {
                            echo "⚠️ DUPLICADOS DETECTADOS:\n";
                            foreach ($duplicados as $idx => $num) {
                                echo "  - Índice $idx: Semana $num\n";
                            }
                        } else {
                            echo "✓ No hay duplicados detectados\n";
                        }
                        ?></pre>

                        <?php if (isset($seccion['debug_log']) && !empty($seccion['debug_log'])): ?>
                            <h6>Debug Log Detallado:</h6>
                            <pre style="background: #fff3cd; padding: 15px; border-radius: 5px; font-size: 11px; max-height: 400px; overflow-y: auto;"><?php
                            foreach ($seccion['debug_log'] as $log_entry) {
                                echo htmlspecialchars($log_entry) . "\n";
                            }
                            ?></pre>
                        <?php endif; ?>

                    <?php else: ?>
                        <p class="text-muted">Sección no encontrada en el curso</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="alert alert-info">
            <?php echo $analisis['mensaje']; ?>
        </div>
    <?php endif; ?>

    <div class="mt-4">
        <a href="report.php?courseid=<?php echo $courseid; ?>" class="btn btn-primary">
            <i class="icon fa fa-arrow-left fa-fw"></i>
            Volver al Reporte
        </a>
        <a href="debug_semanas.php?courseid=<?php echo $courseid; ?>" class="btn btn-secondary">
            <i class="icon fa fa-search fa-fw"></i>
            Ver Debug de Detección
        </a>
    </div>
</div>

<?php
echo $OUTPUT->footer();
