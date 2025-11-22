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
        <h4>Análisis por Secciones</h4>
        <p class="text-muted"><small>Análisis por semanas de las secciones del curso</small></p>

        <?php if (!$analisis['requiere_analisis']): ?>
            <div class="alert alert-secondary">
                <i class="icon fa fa-info-circle fa-fw"></i>
                <?php echo $analisis['mensaje']; ?>
            </div>
        <?php else: ?>
            <?php
            // Calcular métricas generales de cumplimiento
            $material_apoyo = $analisis['secciones']['material_apoyo'];
            $actividades = $analisis['secciones']['actividades_aprendizaje'];

            // Total de semanas encontradas (usar el máximo de ambas secciones)
            $total_semanas_encontradas = max(
                $material_apoyo['total_semanas'],
                $actividades['total_semanas']
            );

            // Verificar si cumple con el mínimo de semanas
            $cumple_minimo_semanas = $total_semanas_encontradas >= $analisis['minimo_semanas'];

            // Calcular porcentaje general (promedio de ambas secciones)
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

            if ($secciones_contadas > 0) {
                $porcentaje_general = round($porcentaje_general / $secciones_contadas, 2);
            }
            ?>

            <!-- Resumen General de Cumplimiento -->
            <div class="general-summary card mb-5 border-<?php echo $cumple_minimo_semanas ? 'success' : 'warning'; ?>">
                <div class="card-header bg-<?php echo $cumple_minimo_semanas ? 'success' : 'warning'; ?> text-white">
                    <h5 class="mb-0">
                        <i class="icon fa fa-chart-bar fa-fw"></i>
                        Resumen General de Cumplimiento
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-box text-center p-4 border rounded bg-light">
                                <h3 class="display-4 mb-0"><?php echo $total_semanas_encontradas; ?></h3>
                                <small class="text-muted">Semanas Detectadas</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center p-4 border rounded bg-light">
                                <h3 class="display-4 mb-0"><?php echo $analisis['minimo_semanas']; ?></h3>
                                <small class="text-muted">Semanas Requeridas</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center p-4 border rounded <?php echo $cumple_minimo_semanas ? 'bg-success' : 'bg-warning'; ?> text-white">
                                <h3 class="display-4 mb-0">
                                    <?php echo $cumple_minimo_semanas ? '✓' : '✗'; ?>
                                </h3>
                                <small><?php echo $cumple_minimo_semanas ? 'Cumple Mínimo' : 'No Cumple Mínimo'; ?></small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center p-4 border rounded bg-light">
                                <h3 class="display-4 mb-0 <?php
                                    if ($porcentaje_general >= 80) echo 'text-success';
                                    else if ($porcentaje_general >= 60) echo 'text-warning';
                                    else echo 'text-danger';
                                ?>">
                                    <?php echo $porcentaje_general; ?>%
                                </h3>
                                <small class="text-muted">Cumplimiento General</small>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="progress" style="height: 30px;">
                            <div class="progress-bar <?php
                                if ($porcentaje_general >= 80) echo 'bg-success';
                                else if ($porcentaje_general >= 60) echo 'bg-warning';
                                else echo 'bg-danger';
                            ?>" role="progressbar" style="width: <?php echo $porcentaje_general; ?>%;">
                                <strong><?php echo $porcentaje_general; ?>%</strong>
                            </div>
                        </div>
                    </div>

                    <?php if (!$cumple_minimo_semanas): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="icon fa fa-exclamation-triangle fa-fw"></i>
                            <strong>Advertencia:</strong> El curso tiene <?php echo $total_semanas_encontradas; ?> semana(s) detectada(s),
                            pero se requieren al menos <?php echo $analisis['minimo_semanas']; ?> semana(s) según la modalidad
                            <strong><?php echo $modalidad_name; ?></strong>.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <h5 class="mt-4 mb-3">Análisis Detallado por Sección</h5>

            <?php
            // Iterar sobre las secciones a analizar
            $secciones_a_mostrar = [
                'material_apoyo' => 'Recursos o Material de Apoyo',
                'actividades_aprendizaje' => 'Actividades de Aprendizaje',
                'actividades_finales' => 'Actividades Finales'
            ];

            foreach ($secciones_a_mostrar as $seccion_key => $seccion_titulo):
                $seccion = $analisis['secciones'][$seccion_key];

                // Solo mostrar si la sección fue encontrada
                if (!$seccion['encontrada']) {
                    continue;
                }
            ?>
                <!-- Sección: <?php echo $seccion_titulo; ?> -->
                <div class="section-analysis mb-5">
                    <div class="section-header card bg-primary text-white mb-3">
                        <div class="card-body">
                            <h5 class="mb-0">
                                <i class="icon fa fa-folder-open fa-fw"></i>
                                <?php echo $seccion_titulo; ?>
                            </h5>
                            <small>Sección: <strong><?php echo format_string($seccion['nombre']); ?></strong></small>
                        </div>
                    </div>

                    <!-- Resumen de la sección -->
                    <div class="analysis-summary card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">Resumen</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($seccion_key === 'actividades_finales'): ?>
                                <!-- Resumen para Actividades Finales -->
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="stat-box text-center p-4 border rounded bg-light">
                                            <h2 class="display-4 mb-0"><?php echo $seccion['total_actividades']; ?></h2>
                                            <small class="text-muted">Actividades Encontradas</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="stat-box text-center p-4 border rounded <?php echo $seccion['cumple'] ? 'bg-success' : 'bg-danger'; ?> text-white">
                                            <h2 class="display-4 mb-0">
                                                <?php echo $seccion['cumple'] ? '✓' : '✗'; ?>
                                            </h2>
                                            <small><?php echo $seccion['cumple'] ? 'Cumple' : 'No Cumple'; ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="stat-box text-center p-4 border rounded bg-light">
                                            <p class="mb-0"><small><?php echo $seccion['mensaje']; ?></small></p>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Resumen para Material de Apoyo y Actividades de Aprendizaje -->
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="stat-box text-center p-4 border rounded bg-light">
                                            <h2 class="display-4 mb-0"><?php echo $seccion['total_semanas']; ?></h2>
                                            <small class="text-muted">Total Semanas</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="stat-box text-center p-4 border rounded bg-light">
                                            <h2 class="display-4 mb-0 <?php echo $seccion['semanas_cumplen'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $seccion['semanas_cumplen']; ?>
                                            </h2>
                                            <small class="text-muted">Semanas que Cumplen</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="stat-box text-center p-4 border rounded bg-light">
                                            <h2 class="display-4 mb-0 <?php
                                                if ($seccion['porcentaje_cumplimiento'] >= 80) echo 'text-success';
                                                else if ($seccion['porcentaje_cumplimiento'] >= 60) echo 'text-warning';
                                                else echo 'text-danger';
                                            ?>">
                                                <?php echo $seccion['porcentaje_cumplimiento']; ?>%
                                            </h2>
                                            <small class="text-muted">Cumplimiento</small>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Detalle por semanas o actividades -->
                    <div class="weeks-detail">
                        <?php if ($seccion_key === 'actividades_finales'): ?>
                            <!-- Detalle de Actividades Finales -->
                            <h6>Detalle de Actividades Finales</h6>

                            <?php if (empty($seccion['actividades'])): ?>
                                <div class="alert alert-warning">
                                    <i class="icon fa fa-exclamation-triangle fa-fw"></i>
                                    No se encontraron actividades finales válidas en esta sección.
                                </div>
                            <?php else: ?>
                                <?php foreach ($seccion['actividades'] as $actividad): ?>
                                    <div class="activity-item card mb-3 <?php echo $actividad['es_valido'] ? 'border-success' : 'border-danger'; ?>">
                                        <div class="card-header <?php echo $actividad['es_valido'] ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong><?php echo format_string($actividad['nombre']); ?></strong>
                                                <span class="badge badge-light <?php echo $actividad['es_valido'] ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $actividad['es_valido'] ? '✓ Cumple' : '✗ No Cumple'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <?php if ($actividad['tipo'] === 'quiz'): ?>
                                                <!-- Detalles para Cuestionario -->
                                                <div class="row mb-3">
                                                    <div class="col-md-3">
                                                        <div class="requirement-box p-3 border rounded <?php echo $actividad['nombre_valido'] ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                                            <i class="icon fa fa-<?php echo $actividad['nombre_valido'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                                            <strong>Nombre:</strong><br>
                                                            <small><?php echo $actividad['nombre_valido'] ? 'Contiene "Evaluación Final" ✓' : 'No contiene "Evaluación Final" ✗'; ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="requirement-box p-3 border rounded <?php echo $actividad['cumple_preguntas'] ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                                            <i class="icon fa fa-<?php echo $actividad['cumple_preguntas'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                                            <strong>Preguntas:</strong><br>
                                                            <small><?php echo $actividad['total_preguntas']; ?> de 30 mínimo <?php echo $actividad['cumple_preguntas'] ? '✓' : '✗'; ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="requirement-box p-3 border rounded <?php echo $actividad['tiene_intentos'] ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                                            <i class="icon fa fa-<?php echo $actividad['tiene_intentos'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                                            <strong>Intentos:</strong><br>
                                                            <small><?php echo $actividad['intentos_permitidos']; ?> intento(s) <?php echo $actividad['tiene_intentos'] ? '✓' : '✗'; ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="requirement-box p-3 border rounded <?php echo $actividad['fecha_valida'] ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                                            <i class="icon fa fa-<?php echo $actividad['fecha_valida'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                                            <strong>Fecha:</strong><br>
                                                            <small><?php echo $actividad['fecha_valida'] ? 'Posterior al inicio ✓' : 'Anterior al inicio ✗'; ?></small>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <!-- Detalles para Tarea -->
                                                <div class="row mb-3">
                                                    <div class="col-md-3">
                                                        <div class="requirement-box p-3 border rounded <?php echo $actividad['nombre_valido'] ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                                            <i class="icon fa fa-<?php echo $actividad['nombre_valido'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                                            <strong>Nombre:</strong><br>
                                                            <small><?php echo $actividad['nombre_valido'] ? 'Contiene "' . $actividad['nombre_esperado'] . '" ✓' : 'No contiene "' . $actividad['nombre_esperado'] . '" ✗'; ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="requirement-box p-3 border rounded <?php echo $actividad['tiene_entregas'] ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                                            <i class="icon fa fa-<?php echo $actividad['tiene_entregas'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                                            <strong>Entregas:</strong><br>
                                                            <small><?php echo $actividad['tiene_entregas'] ? 'Habilitadas ✓' : 'No habilitadas ✗'; ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="requirement-box p-3 border rounded <?php echo $actividad['tiene_configuracion'] ? 'bg-success-light' : 'bg-warning-light'; ?>">
                                                            <i class="icon fa fa-<?php echo $actividad['tiene_configuracion'] ? 'check-circle text-success' : 'info-circle text-warning'; ?> fa-fw"></i>
                                                            <strong>Configuración:</strong><br>
                                                            <small><?php echo $actividad['tiene_configuracion'] ? 'Configurada ✓' : 'Sin fechas'; ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="requirement-box p-3 border rounded <?php echo $actividad['fecha_valida'] ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                                            <i class="icon fa fa-<?php echo $actividad['fecha_valida'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                                            <strong>Fecha:</strong><br>
                                                            <small><?php echo $actividad['fecha_valida'] ? 'Posterior al inicio ✓' : 'Anterior al inicio ✗'; ?></small>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        <?php else: ?>
                            <!-- Detalle por Semanas (Material de Apoyo y Actividades de Aprendizaje) -->
                            <h6>Detalle por Semanas</h6>

                            <?php if (empty($seccion['semanas'])): ?>
                            <div class="alert alert-warning">
                                <i class="icon fa fa-exclamation-triangle fa-fw"></i>
                                No se encontraron semanas en esta sección. Asegúrate de que la sección tenga etiquetas con "Semana 1", "Semana 2", etc.
                            </div>
                        <?php else: ?>
                            <?php
                            $semana_index = 0;
                            foreach ($seccion['semanas'] as $semana):
                                $semana_index++;
                                $collapse_id = "recursos-{$seccion_key}-semana-{$semana_index}";
                            ?>
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
                                <?php if ($seccion_key === 'material_apoyo'): ?>
                                    <!-- Indicadores para Material de Apoyo -->
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
                                <?php else: ?>
                                    <!-- Indicadores para Actividades de Aprendizaje -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <?php
                                            $minimo_req = isset($analisis['minimo_actividades_por_semana']) ? $analisis['minimo_actividades_por_semana'] : 2;
                                            $cumple_actividades = $semana['actividades_validas'] >= $minimo_req;
                                            ?>
                                            <div class="requirement-box p-3 border rounded <?php echo $cumple_actividades ? 'bg-success-light' : 'bg-danger-light'; ?>">
                                                <i class="icon fa fa-<?php echo $cumple_actividades ? 'check-circle text-success' : 'times-circle text-danger'; ?> fa-fw"></i>
                                                <strong>Actividades Válidas:</strong><br>
                                                <small>
                                                    <?php echo $semana['actividades_validas']; ?> de <?php echo $minimo_req; ?> requerida(s)
                                                    <?php echo $cumple_actividades ? '✓' : '✗'; ?>
                                                </small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="requirement-box p-3 border rounded bg-info-light">
                                                <i class="icon fa fa-tasks fa-fw text-info"></i>
                                                <strong>Total Actividades:</strong><br>
                                                <small><?php echo $semana['total_actividades']; ?> actividad(es)</small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Lista de recursos o actividades según la sección -->
                                <?php if ($seccion_key === 'material_apoyo'): ?>
                                    <!-- Tabla de Recursos -->
                                    <?php if (!empty($semana['recursos'])): ?>
                                        <div class="recursos-list">
                                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                                data-toggle="collapse"
                                                data-target="#<?php echo $collapse_id; ?>">
                                                <i class="icon fa fa-chevron-down fa-fw"></i>
                                                Ver detalles de recursos (<?php echo count($semana['recursos']); ?>)
                                            </button>
                                            <div class="collapse mt-3" id="<?php echo $collapse_id; ?>">
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
                                <?php else: ?>
                                    <!-- Tabla de Actividades -->
                                    <?php if (!empty($semana['actividades'])): ?>
                                        <div class="actividades-list">
                                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                                data-toggle="collapse"
                                                data-target="#<?php echo $collapse_id; ?>">
                                                <i class="icon fa fa-chevron-down fa-fw"></i>
                                                Ver detalles de actividades (<?php echo count($semana['actividades']); ?>)
                                            </button>
                                            <div class="collapse mt-3" id="<?php echo $collapse_id; ?>">
                                                <table class="table table-sm table-bordered table-hover">
                                                    <thead class="thead-light">
                                                        <tr>
                                                            <th>Tipo</th>
                                                            <th>Nombre</th>
                                                            <th>Cumple Nombre</th>
                                                            <th>Tiene Completion</th>
                                                            <th>Tiene Interacciones</th>
                                                            <th>Fecha Válida</th>
                                                            <th>Estado</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($semana['actividades'] as $actividad): ?>
                                                            <tr class="<?php echo $actividad['es_actividad_valida'] ? '' : 'table-warning'; ?>">
                                                                <td>
                                                                    <span class="badge badge-primary">
                                                                        <?php echo $actividad['tipo']; ?>
                                                                    </span>
                                                                </td>
                                                                <td><?php echo format_string($actividad['nombre']); ?></td>
                                                                <td class="text-center">
                                                                    <?php echo $actividad['cumple_nombre'] ? '<i class="icon fa fa-check text-success"></i>' : '<i class="icon fa fa-times text-danger"></i>'; ?>
                                                                </td>
                                                                <td class="text-center">
                                                                    <?php echo $actividad['tiene_completion'] ? '<i class="icon fa fa-check text-success"></i>' : '<i class="icon fa fa-times text-danger"></i>'; ?>
                                                                </td>
                                                                <td class="text-center">
                                                                    <?php echo $actividad['tiene_interacciones'] ? '<i class="icon fa fa-check text-success"></i>' : '<i class="icon fa fa-exclamation text-warning"></i>'; ?>
                                                                </td>
                                                                <td class="text-center">
                                                                    <?php echo $actividad['fecha_valida'] ? '<i class="icon fa fa-check text-success"></i>' : '<i class="icon fa fa-times text-danger"></i>'; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($actividad['es_actividad_valida']): ?>
                                                                        <span class="badge badge-success">Válida</span>
                                                                    <?php else: ?>
                                                                        <span class="badge badge-warning">No válida</span>
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
                                            No se encontraron actividades en esta semana.
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php endif; ?>  <!-- Fin del else para semanas -->
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Nota informativa -->
            <div class="alert alert-info mt-4">
                <h6><i class="icon fa fa-info-circle fa-fw"></i> Criterios de Evaluación para Modalidad: <strong><?php echo $modalidad_name; ?></strong>
                <?php if (isset($analisis['duracion_curso']) && $analisis['duracion_curso']): ?>
                    <span class="badge badge-info ml-2"><?php echo $analisis['duracion_curso']; ?> horas</span>
                <?php endif; ?>
                </h6>

                <p><strong>Sección: Recursos o Material de Apoyo</strong></p>
                <ul>
                    <li>Cada semana debe tener al menos <strong><?php echo $analisis['minimo_recursos_por_semana']; ?> recurso(s)</strong> generado por el docente (página, archivo, etiqueta, libro, carpeta, URL)</li>
                    <li>Cada semana debe incluir al menos <strong>1 video</strong> (archivo de video, URL de YouTube/Vimeo, o video embebido)</li>
                    <li>Todos los recursos deben haber sido editados <strong>después de la fecha de inicio del curso</strong> (<?php echo userdate($analisis['course_startdate'], '%d/%m/%Y'); ?>)</li>
                </ul>

                <p><strong>Sección: Actividades de Aprendizaje</strong></p>
                <ul>
                    <li>Cada semana debe tener al menos <strong><?php echo isset($analisis['minimo_actividades_por_semana']) ? $analisis['minimo_actividades_por_semana'] : 2; ?> actividad(es)</strong> válida(s)</li>
                    <li>Nombre debe contener "Actividad" seguido de número (ej: "Actividad 1: Título")</li>
                    <li>Tipos válidos: Tarea, Taller, H5P, Foro, Cuestionario, Encuesta, Lección</li>
                    <li>Debe tener <strong>condiciones de finalización configuradas</strong></li>
                    <li>Debe estar editada <strong>después de la fecha de inicio del curso</strong></li>
                </ul>

                <p><strong>Sección: Actividades Finales</strong></p>
                <ul>
                    <?php if ($analisis['es_distancia']): ?>
                        <li>Debe tener <strong>al menos UNA</strong> de las siguientes actividades:</li>
                        <ul>
                            <li><strong>Evaluación Final</strong> (Cuestionario): mínimo 30 preguntas de opción múltiple, intentos configurados</li>
                            <li><strong>Caso Práctico</strong> (Tarea): con entregas habilitadas</li>
                        </ul>
                    <?php else: ?>
                        <li>Debe tener <strong>al menos UNA</strong> de las siguientes actividades:</li>
                        <ul>
                            <li><strong>Evaluación Final</strong> (Cuestionario): mínimo 30 preguntas de opción múltiple, intentos configurados</li>
                            <li><strong>Caso de Estudio</strong> (Tarea): con entregas habilitadas</li>
                            <li><strong>Portafolio del Estudiante</strong> (Tarea): con entregas habilitadas</li>
                            <li><strong>Actividad Autoinstruccional</strong> (Tarea): con entregas habilitadas</li>
                        </ul>
                    <?php endif; ?>
                    <li>Todas las actividades deben estar editadas <strong>después de la fecha de inicio del curso</strong></li>
                </ul>

                <p><strong>Requisitos generales:</strong></p>
                <ul class="mb-0">
                    <li>La modalidad requiere un mínimo de <strong><?php echo $analisis['minimo_semanas']; ?> semanas</strong> en cada sección
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
