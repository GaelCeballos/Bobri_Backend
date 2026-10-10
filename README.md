# API Backend - Sistema de Gestión de Obras y Gastos

Este repositorio contiene el código fuente del backend (API RESTful) para el sistema de control financiero, registro de gastos en sitio y validación de comprobantes SAT para proyectos de construcción. El sistema está diseñado bajo una arquitectura desacoplada y se ejecuta en contenedores Docker para garantizar la consistencia entre los entornos de desarrollo y producción.

## 🛠 Stack Tecnológico

El proyecto está construido sobre las versiones más recientes y con soporte a largo plazo (LTS) del ecosistema PHP.

| Tecnología | Versión | Propósito |
| :--- | :--- | :--- |
| **PHP** | 8.3 | Lenguaje base, ofrece tipado estricto y alto rendimiento. |
| **Laravel** | 11.x | Framework backend para la construcción ágil de la API REST. |
| **PostgreSQL** | 16 | Base de datos relacional para la integridad financiera e historial de transacciones. |
| **Docker (Sail)**| Última | Orquestación de contenedores para entornos locales y despliegue. |

## 📦 Librerías Principales

| Paquete (Composer) | Descripción y Uso |
| :--- | :--- |
| `laravel/sanctum` | Sistema de autenticación ligero basado en tokens para la conexión segura con la PWA. |
| `maatwebsite/excel` | Generación y exportación de reportes de auditoría y gastos en formato `.xlsx`. |
| `phpcfdi/sat-estado-cfdi` | Consulta directa al Web Service del SAT para validar el estado (Vigente/Cancelado) de los tickets mediante el escaneo QR. |
| `spatie/laravel-query-builder` | Creación rápida de filtros complejos, ordenamiento y paginación en los endpoints de auditoría. |

## ✅ Calidad de Código

Este proyecto utiliza un stack de calidad estándar en el ecosistema Laravel para mantener un código limpio y libre de errores antes de cada despliegue:

| Herramienta | Propósito |
| :--- | :--- |
| **Laravel Pint** | Formateo automático de código (Estilos y estándares PSR). |
| **PHPStan** | Análisis estático de tipos y detección de errores estructurales sin ejecutar el código. |
| **Pest** | Framework para pruebas automatizadas (Testing) de la API. |

> Se requiere obligatoriamente correr las pruebas y el linter antes de abrir y aprobar un Pull Request.

## 🌿 Estrategia de Ramas

Se utiliza un flujo de trabajo basado en Git Flow adaptado para despliegues continuos.

| Rama | Propósito |
| :--- | :--- |
| `main` | Producción. Código 100% estable y auditado. |
| `staging` | QA / Pre-producción. Entorno de pruebas final con datos espejo. |
| `develop` | Integración continua. Base principal para el desarrollo diario. |
| `feature/*` | Desarrollo de nuevas funcionalidades o endpoints. |
| `bugfix/*` | Correcciones de errores no críticos encontrados en desarrollo/QA. |
| `hotfix/*` | Correcciones urgentes para fallos detectados directamente en producción. |

**Flujo de Promoción de Ambientes:**
`feature/*` | `bugfix/*` | `hotfix/*` ➔ `develop` ➔ `staging` ➔ `main`

## 📂 Estructura Base del Proyecto

El sistema adopta la estructura minimalista de Laravel 11, enfocada en la limpieza del directorio principal.

```text
app/
├── Models/              # Representación de tablas (User, Obra, Gasto, Concepto)
├── Http/
│   ├── Controllers/     # Lógica de los endpoints de la API (Api/V1/)
│   ├── Requests/        # Validaciones de formularios y payloads entrantes
│   └── Middleware/      # Filtros de peticiones (Roles, Autenticación)
└── Providers/           # Registro de servicios del sistema
database/
├── migrations/          # Control de versiones de la estructura de PostgreSQL
├── factories/           # Generadores de datos falsos para testing
└── seeders/             # Población inicial de catálogos y administradores
routes/
├── api.php              # Definición exclusiva de rutas RESTful y versiones (v1)
└── console.php          # Comandos de terminal personalizados
tests/
├── Feature/             # Pruebas de integración de los endpoints API
├── Unit/                # Pruebas aisladas de servicios (ej. Lógica del SAT)
└── Pest.php             # Configuración del entorno de testing
