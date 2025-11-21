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
$PAGE->set_url(new moodle_url('/local/cumplimiento_docente/report.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Análisis de Cumplimiento: ' . format_string($course->fullname));
$PAGE->set_heading('Análisis de Cumplimiento Docente');
$PAGE->set_pagelayout('admin');

// Obtener información del curso
$course_info = $DB->get_record_sql("
    SELECT c.id, c.fullname, c.shortname, c.startdate,
           cc.name as categoria
    FROM {course} c
    INNER JOIN {course_categories} cc ON c.category = cc.id
    WHERE c.id = :courseid
", ['courseid' => $courseid]);

// Obtener docentes del curso
$docentes = $DB->get_records_sql("
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
    FROM {user} u
    INNER JOIN {role_assignments} ra ON ra.userid = u.id
    INNER JOIN {role} r ON r.id = ra.roleid
    INNER JOIN {context} ctx ON ctx.id = ra.contextid
    WHERE ctx.instanceid = :courseid
    AND ctx.contextlevel = 50
    AND r.shortname IN ('editingteacher', 'teacher')
    ORDER BY u.lastname, u.firstname
", ['courseid' => $courseid]);

// Obtener modalidad
$modalidad = report_analyzer::get_course_modalidad($courseid);
$modalidad_name = $modalidad ? $modalidad->modalidad_name : 'Sin modalidad';

// Generar análisis
$analisis = report_analyzer::analyze_course_resources($courseid, $modalidad_name);

// Salida de la página
echo $OUTPUT->header();

?>

<div class="cumplimiento-docente-report">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="index.php">Cumplimiento Docente</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                Análisis del Curso
            </li>
        </ol>
    </nav>

    <!-- Información del curso -->
    <div class="course-header card mb-4">
        <div class="card-header bg-primary text-white">
            <h3 class="mb-0"><?php echo format_string($course_info->fullname); ?></h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Código:</strong><br>
                    <?php echo s($course_info->shortname); ?>
                </div>
                <div class="col-md-3">
                    <strong>Categoría:</strong><br>
                    <?php echo format_string($course_info->categoria); ?>
                </div>
                <div class="col-md-3">
                    <strong>Modalidad:</strong><br>
                    <span class="badge badge-info badge-lg"><?php echo $modalidad_name; ?></span>
                </div>
                <div class="col-md-3">
                    <strong>Fecha de Inicio:</strong><br>
                    <?php echo userdate($course_info->startdate, '%d/%m/%Y'); ?>
                </div>
            </div>

            <?php if (!empty($docentes)): ?>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <strong>Docente(s):</strong><br>
                        <?php foreach ($docentes as $docente): ?>
                            <span class="badge badge-secondary">
                                <?php echo format_string($docente->firstname . ' ' . $docente->lastname); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Análisis del curso -->
    <div class="course-analysis">
        <h4>Análisis: Sección "Recursos o Material de Apoyo"</h4>
        <p class="text-muted"><small>Análisis por semanas de la sección de Material de Apoyo del curso</small></p>

        <?php if (!$analisis['requiere_analisis']): ?>
            <div class="alert alert-secondary">
                <i class="icon fa fa-info-circle fa-fw"></i>
                <?php echo $analisis['mensaje']; ?>
            </div>
        <?php else: ?>
            <!-- Resumen general -->
            <div class="analysis-summary card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Resumen General</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-box text-center p-4 border rounded bg-light">
                                <h2 class="display-4 mb-0"><?php echo $analisis['total_semanas']; ?></h2>
                                <small class="text-muted">Total Semanas</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center p-4 border rounded bg-light">
                                <h2 class="display-4 mb-0 <?php echo $analisis['semanas_cumplen'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $analisis['semanas_cumplen']; ?>
                                </h2>
                                <small class="text-muted">Semanas que Cumplen</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center p-4 border rounded bg-light">
                                <h2 class="display-4 mb-0 <?php
                                    if ($analisis['porcentaje_cumplimiento'] >= 80) echo 'text-success';
                                    else if ($analisis['porcentaje_cumplimiento'] >= 60) echo 'text-warning';
                                    else echo 'text-danger';
                                ?>">
                                    <?php echo $analisis['porcentaje_cumplimiento']; ?>%
                                </h2>
                                <small class="text-muted">Cumplimiento</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center p-4 border rounded
                                <?php echo $analisis['cumple_minimo'] ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                                <h2 class="display-4 mb-0"><?php echo $analisis['cumple_minimo'] ? '✓' : '✗'; ?></h2>
                                <small>Cumple Mínimo (<?php echo $analisis['minimo_semanas']; ?> semanas)</small>
                            </div>
                        </div>
                    </div>

                    <!-- Estado general -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <?php
                            $estado = 'No Cumple';
                            $clase = 'danger';
                            if ($analisis['cumple_minimo'] && $analisis['porcentaje_cumplimiento'] >= 80) {
                                $estado = 'Cumple Satisfactoriamente';
                                $clase = 'success';
                            } else if ($analisis['porcentaje_cumplimiento'] >= 60) {
                                $estado = 'Cumple Parcialmente';
                                $clase = 'warning';
                            }
                            ?>
                            <div class="alert alert-<?php echo $clase; ?> text-center">
                                <h5 class="mb-0">
                                    <strong>Estado General:</strong> <?php echo $estado; ?>
                                </h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detalle por semanas -->
            <div class="weeks-detail">
                <h5>Detalle por Semanas</h5>

                <?php if (empty($analisis['semanas'])): ?>
                    <div class="alert alert-warning">
                        <i class="icon fa fa-exclamation-triangle fa-fw"></i>
                        No se encontraron semanas en este curso. Asegúrate de que las secciones tengan nombres como "Semana 1", "Semana 2", etc.
                    </div>
                <?php else: ?>
                    <?php foreach ($analisis['semanas'] as $semana): ?>
                        <div class="week-item card mb-3 <?php echo $semana['cumple'] ? 'border-success' : 'border-danger'; ?>">
                            <div class="card-header <?php echo $semana['cumple'] ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong><?php echo format_string($semana['nombre']); ?></strong>
                                    <span class="badge badge-light <?php echo $semana['cumple'] ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $semana['cumple'] ? '✓ Cumple' : '✗ No Cumple'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Indicadores de cumplimiento -->
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <?php
                                        $cumple_recursos = $semana['recursos_docente_validos'] >= $analisis['minimo_recursos_por_semana'];
                                        ?>
                                        <div class="requirement-box p-3 border rounded <?php echo $cumple_recursos ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                            <i class="icon fa fa-<?php echo $cumple_recursos ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                            <strong>Recursos del Docente:</strong><br>
                                            <small>
                                                <?php echo $semana['recursos_docente_validos']; ?> de <?php echo $analisis['minimo_recursos_por_semana']; ?> requerido(s)
                                                <?php echo $cumple_recursos ? '✓' : '✗'; ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="requirement-box p-3 border rounded <?php echo $semana['tiene_video'] ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                            <i class="icon fa fa-<?php echo $semana['tiene_video'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                            <strong>Video:</strong><br>
                                            <small><?php echo $semana['tiene_video'] ? 'Sí tiene ✓' : 'No tiene ✗'; ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="requirement-box p-3 border rounded bg-info-light">
                                            <i class="icon fa fa-list fa-fw text-info"></i>
                                            <strong>Total Recursos Válidos:</strong><br>
                                            <small><?php echo $semana['recursos_validos']; ?> recurso(s)</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Lista de recursos -->
                                <?php if (!empty($semana['recursos'])): ?>
                                    <div class="recursos-list">
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-toggle="collapse"
                                            data-target="#recursos-semana-<?php echo $semana['semana']; ?>">
                                            <i class="icon fa fa-chevron-down fa-fw"></i>
                                            Ver detalles de recursos (<?php echo count($semana['recursos']); ?>)
                                        </button>
                                        <div class="collapse mt-3" id="recursos-semana-<?php echo $semana['semana']; ?>">
                                            <table class="table table-sm table-bordered table-hover">
                                                <thead class="thead-light">
                                                    <tr>
                                                        <th>Tipo</th>
                                                        <th>Nombre</th>
                                                        <th>Es Recurso Docente</th>
                                                        <th>Es Video</th>
                                                        <th>Fecha Modificación</th>
                                                        <th>Estado</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($semana['recursos'] as $recurso): ?>
                                                        <tr class="<?php echo $recurso['fecha_valida'] ? '' : 'table-warning'; ?>">
                                                            <td>
                                                                <span class="badge badge-secondary">
                                                                    <?php echo $recurso['tipo']; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo format_string($recurso['nombre']); ?></td>
                                                            <td class="text-center">
                                                                <?php echo $recurso['es_recurso_docente'] ? '<i class="icon fa fa-check text-success"></i>' : ''; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php echo $recurso['es_video'] ? '<i class="icon fa fa-video text-primary"></i>' : ''; ?>
                                                            </td>
                                                            <td>
                                                                <small><?php echo userdate($recurso['fecha_modificacion'], '%d/%m/%Y %H:%M'); ?></small>
                                                            </td>
                                                            <td>
                                                                <?php if ($recurso['fecha_valida']): ?>
                                                                    <span class="badge badge-success">Válido</span>
                                                                <?php else: ?>
                                                                    <span class="badge badge-warning">Anterior al inicio</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="icon fa fa-exclamation-triangle fa-fw"></i>
                                        No se encontraron recursos en esta semana.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Nota informativa -->
            <div class="alert alert-info mt-4">
                <h6><i class="icon fa fa-info-circle fa-fw"></i> Criterios de Evaluación para Modalidad: <strong><?php echo $modalidad_name; ?></strong></h6>
                <ul class="mb-0">
                    <li>Cada semana debe tener al menos <strong><?php echo $analisis['minimo_recursos_por_semana']; ?> recurso(s)</strong> generado por el docente (página, archivo, etiqueta, libro, carpeta, URL)</li>
                    <li>Cada semana debe incluir al menos <strong>1 video</strong> (archivo de video, URL de YouTube/Vimeo, o video embebido)</li>
                    <li>Todos los recursos deben haber sido editados <strong>después de la fecha de inicio del curso</strong> (<?php echo userdate($analisis['course_startdate'], '%d/%m/%Y'); ?>)</li>
                    <li>La modalidad requiere un mínimo de <strong><?php echo $analisis['minimo_semanas']; ?> semanas</strong>
                        <?php if ($analisis['es_distancia']): ?>
                            <span class="badge badge-warning">Modalidad Distancia</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <!-- Botones de acción -->
    <div class="action-buttons mt-4 mb-4">
        <a href="index.php" class="btn btn-secondary">
            <i class="icon fa fa-arrow-left fa-fw"></i>
            Volver a la Lista
        </a>
        <a href="<?php echo new moodle_url('/course/view.php', ['id' => $courseid]); ?>"
           class="btn btn-primary"
           target="_blank">
            <i class="icon fa fa-external-link-alt fa-fw"></i>
            Ir al Curso
        </a>
        <?php if ($analisis['requiere_analisis']): ?>
            <button onclick="window.print()" class="btn btn-info">
                <i class="icon fa fa-print fa-fw"></i>
                Imprimir Reporte
            </button>
        <?php endif; ?>
    </div>
</div>

<style>
.cumplimiento-docente-report {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.course-header {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stat-box {
    transition: transform 0.2s;
}

.stat-box:hover {
    transform: translateY(-5px);
}

.week-item {
    transition: all 0.3s;
}

.week-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.requirement-box {
    transition: all 0.2s;
}

.bg-success-light {
    background-color: #d4edda !important;
}

.bg-danger-light {
    background-color: #f8d7da !important;
}

.bg-info-light {
    background-color: #d1ecf1 !important;
}

.badge-lg {
    font-size: 1em;
    padding: 0.5em 1em;
}

@media print {
    .action-buttons,
    .breadcrumb,
    button[data-toggle="collapse"] {
        display: none !important;
    }

    .collapse {
        display: block !important;
    }

    .card {
        page-break-inside: avoid;
    }
}
</style>

<?php
echo $OUTPUT->footer();
