<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Rendering;

use App\Domain\Outreach\Models\TemplateVersion;
use RuntimeException;

/**
 * Reemplaza variables en una plantilla, y nada mas.
 *
 * Deliberadamente NO es un motor de plantillas. No hay Blade, ni condicionales,
 * ni bucles, ni acceso a objetos. Sólo `{{ variable }}` sobre una lista blanca
 * declarada en la version de la plantilla.
 *
 * El motivo es concreto: Blade compila a PHP y ejecuta lo que le pongan. Una
 * plantilla que un usuario del dashboard puede editar y que se renderiza con
 * los datos de un comprador es la superficie de ataque mas atractiva de todo
 * el sistema. Un reemplazo literal sobre una lista blanca no tiene ninguna.
 *
 * Y la lista blanca no es decorativa: una variable no declarada NO se
 * reemplaza en silencio, hace fallar el renderizado. Dejarla pasar como
 * cadena vacia produciria un correo que dice "Hola ," y nadie se enteraria
 * hasta que lo reciba un cliente.
 */
final class TemplateRenderer
{
    /** @var non-empty-string */
    private const PLACEHOLDER = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * @return array{subject: string, text: string, html: string|null}
     */
    public function render(TemplateVersion $version, RecipientContext $context): array
    {
        $declared = $version->declared_variables ?? [];

        return [
            'subject' => $this->replace($version->subject, $context, $declared),
            'text' => $this->replace($version->body_text, $context, $declared),
            'html' => $version->body_html === null
                ? null
                : $this->replace($version->body_html, $context, $declared, escape: true),
        ];
    }

    /**
     * @param  list<string>  $declared
     */
    private function replace(string $template, RecipientContext $context, array $declared, bool $escape = false): string
    {
        $rendered = preg_replace_callback(
            self::PLACEHOLDER,
            function (array $m) use ($context, $declared, $escape): string {
                $name = strtolower($m[1]);

                if (! in_array($name, $declared, strict: true)) {
                    throw new RuntimeException(
                        "La plantilla usa la variable [{$name}], que no está declarada. ".
                        'Variables permitidas: '.(implode(', ', $declared) ?: '(ninguna)').'.'
                    );
                }

                $value = $context->variables[$name] ?? null;

                if ($value === null) {
                    throw new RuntimeException(
                        "El destinatario no tiene valor para [{$name}]. ".
                        'Un correo que dice "Hola ," es peor que uno que no sale.'
                    );
                }

                $rendered = (string) $value;

                // En HTML se escapa siempre. El nombre de un comprador puede
                // venir de un sistema de origen que no valida nada.
                return $escape ? htmlspecialchars($rendered, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $rendered;
            },
            $template,
        );

        // `?? $template` era la unica forma de que saliera un correo con
        // `{{ nombre }}` literal: si PCRE aborta —limite de backtracking sobre
        // un cuerpo grande, por ejemplo— devuelve null, y devolver la
        // plantilla cruda manda el mensaje SIN reemplazar. Es el mismo "Hola
        // ," que esta clase dice hacer imposible, con el agravante de que
        // nadie se entera: no hay error, hay un correo mal escrito.
        //
        // Fallar cuesta una campana que no sale y un error visible en el
        // primer envio. No fallar cuesta la campana entera enviada mal.
        if ($rendered === null) {
            throw new RuntimeException(
                'Falló el reemplazo de variables de la plantilla ('.preg_last_error_msg().'). '.
                'No se envía nada: un correo con las variables sin reemplazar es peor que uno que no sale.'
            );
        }

        return $rendered;
    }
}
