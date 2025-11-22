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
$buscar = optional_param('buscar', 0, PARAM_INT);

// Obtener datos para los filtros
$modalidades = report_analyzer::get_modalidades();
$carreras = report_analyzer::get_carreras($modalidad);
$niveles = report_analyzer::get_niveles($carrera);
$docentes = report_analyzer::get_docentes();

// Obtener cursos si se aplicaron filtros
$cursos = null;
if ($buscar) {
    $filters = [
        'modalidad' => $modalidad,
        'carrera' => $carrera,
        'nivel' => $nivel,
        'docente' => $docente
    ];
    $cursos = report_analyzer::get_cursos($filters);
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
                        <button type="submit" name="buscar" value="1" class="btn btn-primary">
                            Buscar Cursos
                        </button>
                        <a href="<?php echo $PAGE->url; ?>" class="btn btn-secondary">
                            Limpiar Filtros
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php if ($cursos !== null): ?>
        <div class="course-list mt-4">
            <h3>Cursos Encontrados</h3>

            <?php if (empty($cursos)): ?>
                <div class="alert alert-info">
                    No se encontraron cursos con los filtros seleccionados.
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    Se encontraron <strong><?php echo count($cursos); ?></strong> cursos.
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="thead-dark">
                            <tr>
                                <th>Curso</th>
                                <th>Código</th>
                                <th>Categoría</th>
                                <th>Docente</th>
                                <th>Fecha Inicio</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Agrupar cursos por id para evitar duplicados con múltiples docentes
                            $cursos_agrupados = [];
                            foreach ($cursos as $curso) {
                                if (!isset($cursos_agrupados[$curso->id])) {
                                    $cursos_agrupados[$curso->id] = $curso;
                                    $cursos_agrupados[$curso->id]->docentes = [];
                                }
                                if ($curso->docente_id) {
                                    $cursos_agrupados[$curso->id]->docentes[] = (object)[
                                        'id' => $curso->docente_id,
                                        'nombre' => $curso->firstname . ' ' . $curso->lastname,
                                        'email' => $curso->email
                                    ];
                                }
                            }

                            foreach ($cursos_agrupados as $curso):
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo format_string($curso->fullname); ?></strong>
                                    </td>
                                    <td><?php echo s($curso->shortname); ?></td>
                                    <td>
                                        <span class="badge badge-secondary">
                                            <?php echo format_string($curso->categoria); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($curso->docentes)): ?>
                                            <?php foreach ($curso->docentes as $idx => $doc): ?>
                                                <?php if ($idx > 0) echo '<br>'; ?>
                                                <small><?php echo format_string($doc->nombre); ?></small>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <em class="text-muted">Sin docente asignado</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?php echo userdate($curso->startdate, '%d/%m/%Y'); ?></small>
                                    </td>
                                    <td>
                                        <a href="report.php?courseid=<?php echo $curso->id; ?>"
                                           class="btn btn-sm btn-info"
                                           title="Ver análisis del curso">
                                            <i class="icon fa fa-chart-bar fa-fw"></i>
                                            Ver Análisis
                                        </a>
                                        <a href="debug_analisis.php?courseid=<?php echo $curso->id; ?>"
                                           class="btn btn-sm btn-warning"
                                           title="Diagnosticar análisis completo">
                                            <i class="icon fa fa-bug fa-fw"></i>
                                            Debug
                                        </a>
                                        <a href="<?php echo new moodle_url('/course/view.php', ['id' => $curso->id]); ?>"
                                           class="btn btn-sm btn-secondary"
                                           title="Ir al curso"
                                           target="_blank">
                                            <i class="icon fa fa-external-link-alt fa-fw"></i>
                                            Ir al Curso
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Botón para exportar -->
                <div class="text-right mt-3">
                    <a href="export.php?<?php echo http_build_query($_GET); ?>"
                       class="btn btn-success">
                        <i class="icon fa fa-file-excel fa-fw"></i>
                        Exportar Lista a Excel
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

.table-responsive {
    background: white;
    padding: 15px;
    border-radius: 5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table thead th {
    border-top: none;
}

.course-list .badge {
    font-size: 0.85em;
}

.btn-sm {
    margin: 2px 0;
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
