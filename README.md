# Sistema de Reporte de Cumplimiento Docente

Plugin de Moodle para generar reportes de cumplimiento docente basados en análisis de recursos por semana.

## Características Principales

### 1. Filtros Jerárquicos
El sistema implementa un sistema de filtros en cascada:

- **Modalidad** (Categoría padre)
  - **Carrera** (Subcategoría de Modalidad)
    - **Nivel** (Subcategoría de Carrera)
- **Docente** (Filtro independiente)

Los filtros funcionan de manera flexible:
- Seleccionar solo Modalidad muestra todos los cursos de esa modalidad
- Seleccionar Modalidad + Carrera muestra cursos de esa carrera específica
- Seleccionar todos los niveles muestra el nivel más específico

### 2. Análisis por Modalidad

El sistema analiza automáticamente cursos de las siguientes modalidades:

#### Modalidades Presencial, Semipresencial, Híbrida y En Línea
- **Mínimo de semanas:** 3 semanas
- **Recursos por semana:** Al menos 1 recurso generado por el docente
- **Video por semana:** 1 video (obligatorio)
- **Validación de fechas:** Posterior a fecha de inicio del curso

#### Modalidad Distancia
- **Mínimo de semanas:** 8 semanas
- **Recursos por semana:** Al menos 3 recursos generados por el docente
- **Video por semana:** 1 video (obligatorio)
- **Validación de fechas:** Posterior a fecha de inicio del curso

### 3. Análisis de Recursos por Semana

#### Criterios de Evaluación por Semana:

Cada semana se evalúa con los siguientes criterios (según modalidad):

**1. Recursos generados por el docente:**
- **Presencial/Semipresencial/Híbrida/En Línea:** Mínimo 1 recurso
- **Distancia:** Mínimo 3 recursos
- Tipos válidos: Página, Archivo, Etiqueta, Aviso, Libro, Carpeta, URL

**2. Debe incluir 1 video (todas las modalidades):**
- Video cargado como archivo
- Video embebido en etiqueta
- URL de video (YouTube, Vimeo, etc.)

**3. Validación de fechas (todas las modalidades):**
- Todos los recursos deben tener una fecha de edición **posterior** a la fecha de inicio del curso
- Recursos anteriores al inicio del curso no se cuentan como válidos

### 4. Detección de Semanas

El sistema detecta automáticamente las semanas mediante:
- Etiquetas de sección con el patrón "Semana X"
- Todo el contenido después de cada etiqueta pertenece a esa semana
- Hasta que se encuentra la siguiente etiqueta de semana

Ejemplo de estructura:
```
Semana 1
  - Recurso 1
  - Video 1
  - Recurso 2
Semana 2
  - Recurso 1
  - Video 1
...
```

## Instalación

1. Copiar el plugin en `/local/cumplimiento_docente/`
2. Visitar la página de administración de Moodle
3. Completar la instalación del plugin
4. Configurar permisos si es necesario

## Uso

### Generar un Reporte

1. Acceder a **Administración del sitio > Reportes > Cumplimiento Docente**
2. Seleccionar los filtros deseados:
   - Modalidad (opcional)
   - Carrera (opcional, depende de Modalidad)
   - Nivel (opcional, depende de Carrera)
   - Docente (opcional)
3. Hacer clic en **Generar Reporte**

### Interpretar Resultados

El reporte muestra:

#### Resumen General por Curso:
- **Total Semanas**: Número de semanas detectadas
- **Semanas que Cumplen**: Semanas que cumplen ambos criterios
- **% Cumplimiento**: Porcentaje de semanas que cumplen
- **Cumple Mínimo 3 Semanas**: Indicador si tiene al menos 3 semanas

#### Detalle por Semana:
- Estado de cumplimiento (✓ o ✗)
- Número de recursos válidos
- Indicador de recurso del docente
- Indicador de video
- Lista detallada de recursos con:
  - Tipo de recurso
  - Nombre
  - Si es recurso del docente
  - Si es video
  - Validación de fecha

#### Código de Colores:
- 🟢 **Verde**: Semana cumple todos los criterios
- 🔴 **Rojo**: Semana no cumple criterios
- 🟡 **Amarillo**: Recursos con fecha anterior al inicio del curso

### Exportar Resultados

1. Hacer clic en **Exportar a Excel** en la parte inferior del reporte
2. Se descargará un archivo CSV con:
   - Resumen por curso
   - Detalle por semanas
   - Información de docentes

## Estructura del Código

```
local/cumplimiento_docente/
├── classes/
│   └── report_analyzer.php       # Clase principal de análisis
├── lang/
│   ├── en/
│   │   └── local_cumplimiento_docente.php
│   └── es/
│       └── local_cumplimiento_docente.php
├── db/
│   └── access.php                # Definición de permisos
├── index.php                     # Página principal del reporte
├── export.php                    # Exportación a Excel
├── version.php                   # Versión del plugin
└── README.md                     # Este archivo
```

## Métodos Principales

### `report_analyzer::get_modalidades()`
Obtiene todas las modalidades (categorías padre).

### `report_analyzer::get_carreras($modalidad_id)`
Obtiene carreras de una modalidad específica.

### `report_analyzer::get_niveles($carrera_id)`
Obtiene niveles de una carrera específica.

### `report_analyzer::get_cursos($filters)`
Obtiene cursos según los filtros aplicados.

### `report_analyzer::analyze_course_resources($course_id, $modalidad_name)`
Analiza los recursos de un curso:
- Detecta semanas
- Evalúa recursos por semana
- Valida fechas
- Detecta videos
- Calcula cumplimiento

### `report_analyzer::generate_report($filters)`
Genera el reporte completo con todos los análisis.

## Requisitos Técnicos

- Moodle 3.9 o superior
- PHP 7.2 o superior
- Permisos de administrador o manager

## Permisos

El plugin define el permiso `local/cumplimiento_docente:view` asignado por defecto a:
- Administradores
- Managers
- Course creators

## Modalidades Soportadas

El análisis automático funciona para modalidades que contengan en su nombre:
- **"Presencial"** - 3 semanas mínimo, 1 recurso por semana
- **"Semipresencial"** - 3 semanas mínimo, 1 recurso por semana
- **"Híbrida"** - 3 semanas mínimo, 1 recurso por semana
- **"En Línea"** - 3 semanas mínimo, 1 recurso por semana
- **"Distancia"** - 8 semanas mínimo, 3 recursos por semana

Todas las modalidades requieren 1 video por semana y validación de fechas.

Otras modalidades no serán analizadas automáticamente.

## Personalización

### Agregar más tipos de recursos

Editar en `report_analyzer.php`:
```php
$modulos_recurso = ['page', 'resource', 'label', 'folder', 'url', 'book', 'forum'];
// Agregar más tipos según necesidad
```

### Cambiar patrón de detección de semanas

Editar en `report_analyzer.php`:
```php
if (preg_match('/semana\s*(\d+)/i', $section_name, $matches)) {
    // Modificar expresión regular según necesidad
}
```

### Ajustar criterios de validación

Modificar los criterios en el método `analyze_course_resources()`.

## Solución de Problemas

### No se detectan semanas
- Verificar que las secciones tengan el nombre "Semana X"
- El patrón es case-insensitive (SEMANA, Semana, semana)

### Recursos no se cuentan como válidos
- Verificar la fecha de modificación del recurso
- Debe ser posterior a la fecha de inicio del curso

### Videos no se detectan
- Verificar que el archivo tenga MIME type video/*
- Para URLs, verificar que contenga youtube, vimeo, etc.
- Para etiquetas, verificar que contenga tag `<video>` o `<iframe>`

## Soporte

Para reportar problemas o sugerencias, contactar al equipo de desarrollo.

## Licencia

GPL v3

## Changelog

### v1.0 (2025-11-21)
- Versión inicial
- Sistema de filtros jerárquicos
- Análisis por modalidad
- Validación de recursos por semana
- Detección de videos
- Validación de fechas
- Exportación a Excel
