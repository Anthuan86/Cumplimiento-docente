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
$PAGE->set_url(new moodle_url('/local/cumplimiento_docente/debug_semanas.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Debug: Detección de Semanas - ' . format_string($course->fullname));
$PAGE->set_heading('Debug: Detección de Semanas');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

?>

<div class="debug-semanas-container">
    <div class="alert alert-warning">
        <strong>⚠️ Modo Diagnóstico:</strong> Esta página muestra cómo el sistema está detectando las semanas en el curso.
    </div>

    <h3>Curso: <?php echo format_string($course->fullname); ?></h3>

    <?php
    // Obtener todas las secciones del curso
    $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');

    echo "<h4>📁 Secciones del Curso:</h4>";

    foreach ($sections as $section) {
        $section_name = $section->name ? $section->name : "Tema " . $section->section;

        echo "<div class='card mb-3'>";
        echo "<div class='card-header bg-light'>";
        echo "<h5>Sección: " . format_string($section_name) . " (ID: {$section->id})</h5>";
        echo "<small>Section Number: {$section->section} | Visible: " . ($section->visible ? 'Sí' : 'No') . "</small>";
        echo "</div>";
        echo "<div class='card-body'>";

        // Verificar si tiene sequence
        if (empty($section->sequence)) {
            echo "<p class='text-muted'>Esta sección no tiene módulos (sequence vacío)</p>";
        } else {
            echo "<p><strong>Sequence:</strong> {$section->sequence}</p>";

            // Obtener módulos de esta sección
            $module_ids = explode(',', $section->sequence);
            $module_ids = array_filter($module_ids);

            if (!empty($module_ids)) {
                list($in_sql, $params) = $DB->get_in_or_equal($module_ids, SQL_PARAMS_NAMED);

                $sql = "SELECT cm.id, cm.module, cm.instance, m.name as modname
                        FROM {course_modules} cm
                        INNER JOIN {modules} m ON m.id = cm.module
                        WHERE cm.id $in_sql
                        ORDER BY cm.id ASC";

                $modules = $DB->get_records_sql($sql, $params);

                // Ordenar según sequence
                $ordered_modules = [];
                foreach ($module_ids as $mid) {
                    if (isset($modules[$mid])) {
                        $ordered_modules[] = $modules[$mid];
                    }
                }

                echo "<table class='table table-sm table-bordered'>";
                echo "<thead><tr><th>Orden</th><th>ID</th><th>Tipo</th><th>Contenido</th><th>¿Es Semana?</th></tr></thead>";
                echo "<tbody>";

                $orden = 1;
                foreach ($ordered_modules as $module) {
                    echo "<tr>";
                    echo "<td>{$orden}</td>";
                    echo "<td>{$module->id}</td>";
                    echo "<td><span class='badge badge-info'>{$module->modname}</span></td>";

                    // Obtener contenido
                    $contenido = '';
                    $es_semana = false;
                    $semana_numero = '';

                    if ($module->modname === 'label') {
                        $label = $DB->get_record('label', ['id' => $module->instance], 'intro, name');
                        if ($label) {
                            $contenido = strip_tags($label->intro . ' ' . $label->name);
                            $contenido = substr($contenido, 0, 100); // Truncar

                            // Verificar si es semana
                            if (preg_match('/semana\s*(\d+)/i', $label->intro . ' ' . $label->name, $matches)) {
                                $es_semana = true;
                                $semana_numero = $matches[1];
                            }
                        }
                    } else {
                        try {
                            $instancia = $DB->get_record($module->modname, ['id' => $module->instance]);
                            if ($instancia && isset($instancia->name)) {
                                $contenido = $instancia->name;
                            }
                        } catch (Exception $e) {
                            $contenido = "Error al obtener";
                        }
                    }

                    echo "<td>" . s($contenido) . "</td>";

                    if ($es_semana) {
                        echo "<td class='bg-success text-white'><strong>✓ SEMANA {$semana_numero}</strong></td>";
                    } else {
                        echo "<td>-</td>";
                    }

                    echo "</tr>";
                    $orden++;
                }

                echo "</tbody></table>";
            }
        }

        echo "</div>";
        echo "</div>";
    }
    ?>

    <div class="mt-4">
        <a href="index.php" class="btn btn-secondary">← Volver</a>
        <a href="report.php?courseid=<?php echo $courseid; ?>" class="btn btn-primary">Ver Reporte Normal</a>
    </div>
</div>

<style>
.debug-semanas-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.card-header h5 {
    margin: 0;
}

.bg-success {
    background-color: #28a745 !important;
}
</style>

<?php
echo $OUTPUT->footer();
