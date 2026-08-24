<?php

namespace Softbean\Telemetry\Recording;

use Illuminate\Support\Str;

/**
 * Decide o que pode sair do produto.
 *
 * A trilha precisa registrar que um campo mudou, mas mandar senha ou token
 * para outro sistema seria criar um segundo lugar de onde vazar. Aqui o valor
 * vira uma marca e a informacao util (mudou / nao mudou) se preserva.
 */
class Masker
{
    private array $mascarados;

    private array $dadoPessoal;

    private array $ignorados;

    public function __construct(?array $config = null)
    {
        $config ??= config('softbean-telemetry.auditoria', []);

        $this->mascarados = array_map('strtolower', $config['campos_mascarados'] ?? []);
        $this->dadoPessoal = array_map('strtolower', $config['campos_dado_pessoal'] ?? []);
        $this->ignorados = array_map('strtolower', $config['ignorar_campos'] ?? []);
    }

    /**
     * Monta o diff campo a campo, ja mascarado.
     *
     * @return array<string, array{de: mixed, para: mixed}>
     */
    public function diff(array $antes, array $depois): array
    {
        $diff = [];

        foreach ($depois as $campo => $novo) {
            if ($this->deveIgnorar($campo)) {
                continue;
            }

            $velho = $antes[$campo] ?? null;

            // Comparacao frouxa de proposito: o Eloquent devolve "10" e 10
            // conforme o driver, e isso nao e uma alteracao real.
            if ($velho == $novo && gettype($velho) === gettype($novo)) {
                continue;
            }

            if ($velho === null && $novo === null) {
                continue;
            }

            $diff[$campo] = [
                'de' => $this->valor($campo, $velho),
                'para' => $this->valor($campo, $novo),
            ];
        }

        return $diff;
    }

    /**
     * Mascara um diff que ja veio pronto de fora.
     *
     * O diff automatico ja sai mascarado de dentro do diff(), mas quem chama
     * auditar() a mao monta o array como quiser — e nada impede que ponha uma
     * senha ali sem perceber. Esta e a ultima barreira antes do dado sair do
     * produto.
     *
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    public function mascararDiff(array $alteracoes): array
    {
        $seguro = [];

        foreach ($alteracoes as $campo => $valores) {
            $campo = (string) $campo;

            if ($this->deveIgnorar($campo)) {
                continue;
            }

            if (! $this->ehMascarado($campo)) {
                $seguro[$campo] = $valores;

                continue;
            }

            // Preserva a forma do registro (mudou de algo para algo) sem
            // revelar nenhum dos dois valores.
            $seguro[$campo] = is_array($valores)
                && (array_key_exists('de', $valores) || array_key_exists('para', $valores))
                    ? [
                        'de' => ($valores['de'] ?? null) === null ? null : '***',
                        'para' => ($valores['para'] ?? null) === null ? null : '***',
                    ]
                    : '***';
        }

        return $seguro;
    }

    /**
     * Retrato do que existia, para o evento de exclusao.
     *
     * O diff() percorre o "depois", que numa exclusao e vazio — usar ele aqui
     * produziria um evento dizendo "excluiu" sem dizer o que. E exatamente na
     * exclusao que a trilha precisa guardar o conteudo: depois nao ha mais
     * onde consultar.
     *
     * @param  array<string, mixed>  $original
     * @return array<string, array{de: mixed, para: null}>
     */
    public function snapshotRemocao(array $original): array
    {
        $retrato = [];

        foreach ($original as $campo => $valor) {
            $campo = (string) $campo;

            if ($this->deveIgnorar($campo) || $valor === null) {
                continue;
            }

            $retrato[$campo] = ['de' => $this->valor($campo, $valor), 'para' => null];
        }

        return $retrato;
    }

    public function deveIgnorar(string $campo): bool
    {
        return in_array(strtolower($campo), $this->ignorados, true);
    }

    public function ehMascarado(string $campo): bool
    {
        $campo = strtolower($campo);

        foreach ($this->mascarados as $padrao) {
            if (str_contains($campo, $padrao)) {
                return true;
            }
        }

        return false;
    }

    /** O diff toca dado pessoal? Isso encurta a retencao do evento no hub. */
    public function contemDadoPessoal(array $campos): bool
    {
        foreach ($campos as $campo) {
            $campo = strtolower((string) $campo);

            foreach ($this->dadoPessoal as $padrao) {
                if (str_contains($campo, $padrao)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function valor(string $campo, mixed $valor): mixed
    {
        if ($this->ehMascarado($campo)) {
            return $valor === null ? null : '***';
        }

        if (is_string($valor) && Str::length($valor) > 500) {
            return Str::limit($valor, 500);
        }

        if (is_object($valor)) {
            return method_exists($valor, '__toString') ? (string) $valor : get_class($valor);
        }

        return $valor;
    }
}
