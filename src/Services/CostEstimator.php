<?php

namespace AiWooSeo\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Estimates the AI generation cost for a bulk job before it runs.
 * All costs are approximate and should be presented to users with a disclaimer.
 */
class CostEstimator {

    /**
     * Approximate cost per 1 000 tokens for each provider/model combination.
     * Format: [ 'provider/model' => [ 'input' => float, 'output' => float ] ]
     */
    private const COST_PER_1K_TOKENS = [
        'openai/gpt-4o-mini'                        => [ 'input' => 0.000150, 'output' => 0.000600 ],
        'openai/gpt-4o'                             => [ 'input' => 0.002500, 'output' => 0.010000 ],
        'anthropic/claude-3-haiku-20240307'         => [ 'input' => 0.000250, 'output' => 0.001250 ],
        'anthropic/claude-haiku-4-5-20251001'       => [ 'input' => 0.000250, 'output' => 0.001250 ],
        'anthropic/claude-3-sonnet-20240229'        => [ 'input' => 0.003000, 'output' => 0.015000 ],
        'groq/llama-3.3-70b-versatile'              => [ 'input' => 0.000059, 'output' => 0.000079 ],
        'groq/llama-3.1-8b-instant'                 => [ 'input' => 0.000005, 'output' => 0.000008 ],
        'groq/gemma2-9b-it'                         => [ 'input' => 0.000020, 'output' => 0.000020 ],
        'groq/mixtral-8x7b-32768'                   => [ 'input' => 0.000024, 'output' => 0.000024 ],
        'gemini/gemini-2.0-flash'                   => [ 'input' => 0.000100, 'output' => 0.000400 ],
        'gemini/gemini-1.5-flash'                   => [ 'input' => 0.000075, 'output' => 0.000300 ],
        'gemini/gemini-1.5-pro'                     => [ 'input' => 0.001250, 'output' => 0.005000 ],
    ];

    /** Empirical average tokens consumed per product. */
    private const AVG_INPUT_TOKENS  = 800;
    private const AVG_OUTPUT_TOKENS = 300;

    /**
     * Estimate cost for a bulk generation job.
     *
     * @param int    $productCount Number of products to process.
     * @param string $provider     AI provider slug (openai, anthropic, groq, gemini).
     * @param string $model        Model identifier (e.g. gpt-4o-mini).
     * @return array {
     *   products: int,
     *   provider: string,
     *   model: string,
     *   estimated_cost_usd: float,
     *   cost_per_product: float,
     *   disclaimer: string
     * }
     */
    public static function estimateForJob( int $productCount, string $provider, string $model ): array {
        $key   = strtolower( $provider ) . '/' . strtolower( $model );
        $rates = self::COST_PER_1K_TOKENS[ $key ] ?? [ 'input' => 0.000150, 'output' => 0.000600 ];

        $input_cost  = ( $productCount * self::AVG_INPUT_TOKENS  / 1000 ) * $rates['input'];
        $output_cost = ( $productCount * self::AVG_OUTPUT_TOKENS / 1000 ) * $rates['output'];
        $total       = $input_cost + $output_cost;
        $per_product = $productCount > 0 ? $total / $productCount : 0.0;

        return [
            'products'           => $productCount,
            'provider'           => $provider,
            'model'              => $model,
            'estimated_cost_usd' => round( $total, 6 ),
            'cost_per_product'   => round( $per_product, 6 ),
            'disclaimer'         => 'Estimate only. Actual cost depends on product description length.',
        ];
    }
}
