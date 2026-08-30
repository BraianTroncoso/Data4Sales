import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/*
 * Tokens de marca Data4Sales.
 *
 * Los valores no son inventados: salen de las variables CSS del sitio de
 * producción (data4sales.webflow.shared.css) — `--primario--azul`,
 * `--new-violeta-primario`, `--otros--negro`, `--sombras--principal`, etc.
 * Se nombran por ROL y no por color ("brand", no "azul") para que un cambio
 * de paleta no obligue a renombrar clases en cada pantalla.
 *
 * `darkMode: 'class'` es deliberado. Antes no estaba declarado, así que
 * Tailwind caía a 'media': el modo oscuro se activaba solo con la preferencia
 * del sistema operativo, sin toggle y sin forma de desactivarlo, dejando el
 * login en claro y el enlace activo del nav a 1.2:1 de contraste. Con 'class'
 * las utilidades `dark:` que ya existen quedan inertes hasta que se agregue
 * un toggle que ponga la clase en <html>.
 */

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Outfit', ...defaultTheme.fontFamily.sans],
                // Roboto trae cifras tabulares de ancho parejo. Se reserva para
                // columnas de números, donde el salto de ancho entre dígitos de
                // una geométrica como Outfit se nota al scrollear una tabla.
                num: ['Roboto', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                /*
                 * ESCALA INVERTIDA — leer antes de tocar.
                 *
                 * El producto de Data4Sales es oscuro (#121212). En una interfaz
                 * oscura los tintes van hacia el negro y el texto hacia la luz,
                 * así que en cada escala 50 es el TINTE MÁS OSCURO (fondo de una
                 * tarjeta de estado) y 700 el TONO MÁS CLARO (texto legible sobre
                 * ese tinte). El 500 sigue siendo el color puro de la marca.
                 *
                 * Es exactamente el patrón de sus KPI: fondo tintado al ~10% con
                 * borde del acento puro. Los tintes 50 de info/success/warning son
                 * los valores muestreados de sus propias capturas.
                 */

                // Acción principal. Violeta #7340ff: CTA, link, foco, seleccionado.
                brand: {
                    50: '#1b1235',
                    100: '#241a4a',
                    200: '#2f2266',
                    300: '#4a2fa3',
                    400: '#5c39d1',
                    500: '#7340ff',
                    600: '#9a7bff',
                    700: '#b9a3ff',
                    800: '#d5c8ff',
                    900: '#ebe4ff',
                    950: '#f6f3ff',
                    DEFAULT: '#7340ff',
                },

                // Azul #5465ff: era la acción del sistema anterior. Hoy es un
                // color de dato, no de acción.
                info: {
                    50: '#182430',
                    100: '#1d2f45',
                    500: '#3a8bdf',
                    600: '#5fa5e8',
                    700: '#8fc1ef',
                    DEFAULT: '#3a8bdf',
                },

                success: {
                    50: '#11241f',
                    100: '#16342b',
                    500: '#06c996',
                    600: '#2ad6a8',
                    700: '#6ee7c3',
                    DEFAULT: '#06c996',
                },
                warning: {
                    50: '#292115',
                    100: '#3a2e19',
                    500: '#fea82f',
                    600: '#ffbb5c',
                    700: '#ffd08a',
                    DEFAULT: '#fea82f',
                },
                danger: {
                    50: '#2a1410',
                    100: '#3d1a13',
                    500: '#ff2e00',
                    600: '#ff5c38',
                    700: '#ff8f75',
                    DEFAULT: '#ff2e00',
                },

                /*
                 * Texto. `ink` es el color del TEXTO, no "lo oscuro": sobre fondo
                 * negro es casi blanco. #f2f2f2 es el blanco de marca (404 usos en
                 * su CSS); el blanco puro sólo va sobre el violeta.
                 */
                ink: {
                    DEFAULT: '#f2f2f2',
                    soft: '#c9ccd1',
                    muted: '#8b9197',
                    faint: '#6b7178',
                },

                // Superficies. La tarjeta se despega por BORDE, no por sombra.
                canvas: '#121212',
                surface: '#181818',
                raised: '#2d2d2d',
                /*
                 * Los bordes son el ÚNICO mecanismo de jerarquía de este sistema
                 * (no hay sombras: sobre negro no se ven). Por eso están medidos:
                 * `line-strong` llega a 3:1 contra las superficies, que es lo que
                 * WCAG 1.4.11 exige para el límite de un control. Los valores
                 * anteriores (#2f3237 / #40444b) daban 1.38 y 1.81 — una tarjeta
                 * que no se despegaba del fondo por ningún medio.
                 */
                line: {
                    DEFAULT: '#565c66',
                    strong: '#6a717c',
                },
            },

            borderRadius: {
                // El look de marca es de esquinas muy redondeadas: en su CSS
                // dominan 10 / 20 / 40px y una familia de píldoras 96-100px.
                field: '10px',
                chip: '8px',
                card: '20px',
                panel: '28px',
                pill: '999px',
            },

            boxShadow: {
                /*
                 * En su sistema la elevación NO se comunica con sombra gris: las
                 * tarjetas viven en `box-shadow: 0 0 #0003` (blur cero, existe sólo
                 * para que la transición anime) y el hover enciende un halo violeta.
                 * Es un lenguaje de neón sobre negro, no de material.
                 */
                card: '0 1px 2px 0 rgb(0 0 0 / 0.45)',
                'card-hover': '0 0 24px -2px rgb(115 64 255 / 0.35)',
                brand: '0 8px 20px -8px rgb(115 64 255 / 0.55)',
                glow: '0 0 20px rgb(115 64 255 / 0.45)',
                pop: '0 12px 40px -8px rgb(0 0 0 / 0.7)',
            },
        },
    },

    plugins: [forms],
};
