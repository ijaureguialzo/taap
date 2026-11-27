# Tasks

## 1. Modelo y cliente API

- [x] 1.1 DTO `FileEntryDTO` + métodos de `ApiClient` para listar, crear carpeta, subir, descargar, renombrar, borrar (`/api/v1/files/*`); verificar unit test que el DTO decodifica y que el cliente mapea los errores HTTP

## 2. Vista «Archivos» en la app

- [x] 2.1 `FilesView`: navegación por ruta (`.navigationStack`), listado con icono de tipo, subida (picker document), descarga de ficheros/zip, borrado con confirmación, renombrar; verificar con SwiftUI test que la lista se carga y la ruta de navegación funciona
- [x] 2.2 Integrar «Archivos» en `MainTabView` (sección/tab) + i18n es/en/eu; verificar con preview + test de localización

## 3. Verificación final

- [x] 3.1 Suite de unit tests de la app + suite PHP; verificar con `xcodebuild test -scheme Taap -only-testing:TaapUnitTests` (o equivalente en el CI actual de la app)
