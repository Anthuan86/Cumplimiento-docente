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

// Configurar la página
$PAGE->set_url(new moodle_url('/local/cumplimiento_docente/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_cumplimiento_docente'));
$PAGE->set_heading(get_string('pluginname', 'local_cumplimiento_docente'));
$PAGE->set_pagelayout('admin');

// Procesar filtros
$modalidad = optional_param('modalidad', 0, PARAM_INT);
$carrera = optional_param('carrera', 0, PARAM_INT);
$nivel = optional_param('nivel', 0, PARAM_INT);
$docente = optional_param('docente', 0, PARAM_INT);
$generar = optional_param('generar', 0, PARAM_INT);

// Obtener datos para los filtros
$modalidades = report_analyzer::get_modalidades();
$carreras = report_analyzer::get_carreras($modalidad);
$niveles = report_analyzer::get_niveles($carrera);
$docentes = report_analyzer::get_docentes();

// Generar reporte si se solicitó
$reporte = null;
if ($generar) {
    $filters = [
        'modalidad' => $modalidad,
        'carrera' => $carrera,
        'nivel' => $nivel,
        'docente' => $docente
    ];
    $reporte = report_analyzer::generate_report($filters);
}

// Salida de la página
echo $OUTPUT->header();

?>

<div class="cumplimiento-docente-container">
    <h2>Reporte de Cumplimiento Docente</h2>

    <form method="GET" action="<?php echo $PAGE->url; ?>" class="mform">
        <div class="filter-section card">
            <div class="card-body">
                <h4>Filtros de Búsqueda</h4>

                <!-- Filtro de Modalidad -->
                <div class="form-group row">
                    <label for="modalidad" class="col-md-3 col-form-label">Modalidad:</label>
                    <div class="col-md-9">
                        <select name="modalidad" id="modalidad" class="form-control" onchange="this.form.submit()">
                            <option value="0">-- Todas las modalidades --</option>
                            <?php foreach ($modalidades as $mod): ?>
                                <option value="<?php echo $mod->id; ?>"
                                    <?php echo ($modalidad == $mod->id) ? 'selected' : ''; ?>>
                                    <?php echo format_string($mod->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Filtro de Carrera -->
                <div class="form-group row">
                    <label for="carrera" class="col-md-3 col-form-label">Carrera:</label>
                    <div class="col-md-9">
                        <select name="carrera" id="carrera" class="form-control"
                            <?php echo empty($carreras) ? 'disabled' : ''; ?>
                            onchange="this.form.submit()">
                            <option value="0">-- Todas las carreras --</option>
                            <?php foreach ($carreras as $carr): ?>
                                <option value="<?php echo $carr->id; ?>"
                                    <?php echo ($carrera == $carr->id) ? 'selected' : ''; ?>>
                                    <?php echo format_string($carr->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Filtro de Nivel -->
                <div class="form-group row">
                    <label for="nivel" class="col-md-3 col-form-label">Nivel:</label>
                    <div class="col-md-9">
                        <select name="nivel" id="nivel" class="form-control"
                            <?php echo empty($niveles) ? 'disabled' : ''; ?>>
                            <option value="0">-- Todos los niveles --</option>
                            <?php foreach ($niveles as $niv): ?>
                                <option value="<?php echo $niv->id; ?>"
                                    <?php echo ($nivel == $niv->id) ? 'selected' : ''; ?>>
                                    <?php echo format_string($niv->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Filtro de Docente -->
                <div class="form-group row">
                    <label for="docente" class="col-md-3 col-form-label">Docente:</label>
                    <div class="col-md-9">
                        <select name="docente" id="docente" class="form-control">
                            <option value="0">-- Todos los docentes --</option>
                            <?php foreach ($docentes as $doc): ?>
                                <option value="<?php echo $doc->id; ?>"
                                    <?php echo ($docente == $doc->id) ? 'selected' : ''; ?>>
                                    <?php echo format_string($doc->lastname . ', ' . $doc->firstname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Botones de acción -->
                <div class="form-group row">
                    <div class="col-md-9 offset-md-3">
                        <button type="submit" name="generar" value="1" class="btn btn-primary">
                            Generar Reporte
                        </button>
                        <a href="<?php echo $PAGE->url; ?>" class="btn btn-secondary">
                            Limpiar Filtros
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php if ($reporte !== null): ?>
        <div class="report-results mt-4">
            <h3>Resultados del Análisis</h3>

            <?php if (empty($reporte)): ?>
                <div class="alert alert-info">
                    No se encontraron cursos con los filtros seleccionados.
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    Se encontraron <strong><?php echo count($reporte); ?></strong> cursos para analizar.
                </div>

                <?php foreach ($reporte as $item): ?>
                    <?php
                    $curso = $item['curso'];
                    $modalidad_nombre = $item['modalidad'];
                    $analisis = $item['analisis'];
                    ?>

                    <div class="course-report card mb-3">
                        <div class="card-header">
                            <h5>
                                <?php echo format_string($curso->fullname); ?>
                                <span class="badge badge-info"><?php echo $modalidad_nombre; ?></span>
                            </h5>
                            <?php if ($curso->docente_id): ?>
                                <small class="text-muted">
                                    Docente: <?php echo format_string($curso->firstname . ' ' . $curso->lastname); ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="card-body">
                            <?php if (!$analisis['requiere_analisis']): ?>
                                <div class="alert alert-secondary">
                                    <?php echo $analisis['mensaje']; ?>
                                </div>
                            <?php else: ?>
                                <!-- Resumen general -->
                                <div class="analysis-summary mb-3">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="stat-box text-center p-3 border rounded">
                                                <h3><?php echo $analisis['total_semanas']; ?></h3>
                                                <small>Total Semanas</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="stat-box text-center p-3 border rounded">
                                                <h3><?php echo $analisis['semanas_cumplen']; ?></h3>
                                                <small>Semanas que Cumplen</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="stat-box text-center p-3 border rounded">
                                                <h3><?php echo $analisis['porcentaje_cumplimiento']; ?>%</h3>
                                                <small>Cumplimiento</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="stat-box text-center p-3 border rounded
                                                <?php echo $analisis['cumple_minimo'] ? 'bg-success text-white' : 'bg-warning'; ?>">
                                                <h3><?php echo $analisis['cumple_minimo'] ? '✓' : '✗'; ?></h3>
                                                <small>Mínimo 3 Semanas</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Detalle por semanas -->
                                <h6>Detalle por Semanas:</h6>
                                <div class="weeks-detail">
                                    <?php foreach ($analisis['semanas'] as $semana): ?>
                                        <div class="week-item card mb-2
                                            <?php echo $semana['cumple'] ? 'border-success' : 'border-danger'; ?>">
                                            <div class="card-header
                                                <?php echo $semana['cumple'] ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                                                <strong><?php echo $semana['nombre']; ?></strong>
                                                <span class="float-right">
                                                    <?php echo $semana['cumple'] ? '✓ Cumple' : '✗ No Cumple'; ?>
                                                </span>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-4">
                                                        <strong>Recursos válidos:</strong>
                                                        <?php echo $semana['recursos_validos']; ?>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <strong>Tiene recurso del docente:</strong>
                                                        <?php echo $semana['tiene_recurso'] ? '✓ Sí' : '✗ No'; ?>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <strong>Tiene video:</strong>
                                                        <?php echo $semana['tiene_video'] ? '✓ Sí' : '✗ No'; ?>
                                                    </div>
                                                </div>

                                                <?php if (!empty($semana['recursos'])): ?>
                                                    <div class="recursos-list mt-2">
                                                        <button class="btn btn-sm btn-link" type="button"
                                                            data-toggle="collapse"
                                                            data-target="#recursos-semana-<?php echo $curso->id . '-' . $semana['semana']; ?>">
                                                            Ver recursos (<?php echo count($semana['recursos']); ?>)
                                                        </button>
                                                        <div class="collapse"
                                                            id="recursos-semana-<?php echo $curso->id . '-' . $semana['semana']; ?>">
                                                            <table class="table table-sm table-striped">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Tipo</th>
                                                                        <th>Nombre</th>
                                                                        <th>Es Recurso</th>
                                                                        <th>Es Video</th>
                                                                        <th>Fecha Válida</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($semana['recursos'] as $recurso): ?>
                                                                        <tr class="<?php echo $recurso['fecha_valida'] ? '' : 'table-warning'; ?>">
                                                                            <td><?php echo $recurso['tipo']; ?></td>
                                                                            <td><?php echo format_string($recurso['nombre']); ?></td>
                                                                            <td><?php echo $recurso['es_recurso_docente'] ? '✓' : ''; ?></td>
                                                                            <td><?php echo $recurso['es_video'] ? '✓' : ''; ?></td>
                                                                            <td>
                                                                                <?php
                                                                                if ($recurso['fecha_valida']) {
                                                                                    echo '✓ ' . userdate($recurso['fecha_modificacion'], '%d/%m/%Y');
                                                                                } else {
                                                                                    echo '✗ Anterior al inicio';
                                                                                }
                                                                                ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Botón para exportar -->
                <div class="text-right mt-3">
                    <a href="export.php?<?php echo http_build_query($_GET); ?>"
                       class="btn btn-success">
                        Exportar a Excel
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.cumplimiento-docente-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.filter-section {
    background: #f8f9fa;
    margin-bottom: 20px;
}

.stat-box {
    background: #fff;
}

.stat-box h3 {
    margin: 0;
    font-size: 2em;
}

.week-item {
    transition: all 0.3s;
}

.week-item:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.course-report {
    border-left: 4px solid #007bff;
}

.analysis-summary .stat-box {
    transition: transform 0.2s;
}

.analysis-summary .stat-box:hover {
    transform: translateY(-5px);
}
</style>

<script>
// Deshabilitar campos dependientes si no hay selección padre
document.addEventListener('DOMContentLoaded', function() {
    const modalidadSelect = document.getElementById('modalidad');
    const carreraSelect = document.getElementById('carrera');
    const nivelSelect = document.getElementById('nivel');

    // Auto-submit al cambiar modalidad o carrera para cargar datos dependientes
    modalidadSelect.addEventListener('change', function() {
        if (this.value == 0) {
            carreraSelect.value = 0;
            nivelSelect.value = 0;
        }
    });

    carreraSelect.addEventListener('change', function() {
        if (this.value == 0) {
            nivelSelect.value = 0;
        }
    });
});
</script>

<?php
echo $OUTPUT->footer();
