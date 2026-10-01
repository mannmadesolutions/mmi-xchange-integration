<?php
/**
 * MMI_Xchange_Logs
 *
 * Reads and parses this plugin's own MMI_Logger category ('xchange' —
 * mmi-hub/logs/xchange.log) for the Logs tab. Every MMI_Logger::info/warn/
 * error/debug() call anywhere in this plugin already writes here (account
 * checks, order sync, checkout reserve/finalize, webhook receipts, COGS
 * backfill, etc.) — this class only reads and filters, it never writes
 * (writing stays MMI_Logger's job, per this project's "one logger, one
 * location" rule).
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Logs {

    const CATEGORY = 'xchange';

    /**
     * One MMI_Logger entry: "[Y-m-d H:i:s.u] [LEVEL] [Source] message | k=v, k=v".
     * Source is optional (omitted when MMI_Logger::log() is called with no
     * $source arg) — every call site in this plugin does pass one, but the
     * pattern doesn't assume it.
     */
    const LINE_PATTERN = '/^\[(?<ts>[^\]]+)\]\s\[(?<level>[A-Z]+)\](?:\s\[(?<source>[^\]]+)\])?\s(?<rest>.*)$/s';

    /**
     * @param array{level?:string, source?:string, search?:string, limit?:int} $args
     * @return array{
     *   file_exists: bool, file_size: int, file_modified: ?string,
     *   total_lines: int, total_matching: int, truncated: bool,
     *   sources: string[],
     *   entries: array<int, array{ts:string, level:string, source:string, message:string, raw:string}>,
     * }
     */
    public static function get_entries( array $args = [] ): array {
        $level  = strtoupper( (string) ( $args['level']  ?? 'ALL' ) );
        $source = (string) ( $args['source'] ?? 'all' );
        $search = trim( (string) ( $args['search'] ?? '' ) );
        $limit  = max( 1, min( 2000, (int) ( $args['limit'] ?? 300 ) ) );

        $file = MMI_Logger::get_log_file( self::CATEGORY );

        $result = [
            'file_exists'    => false,
            'file_size'      => 0,
            'file_modified'  => null,
            'total_lines'    => 0,
            'total_matching' => 0,
            'truncated'      => false,
            'sources'        => [],
            'entries'        => [],
        ];

        if ( ! file_exists( $file ) ) {
            return $result;
        }

        $result['file_exists']   = true;
        $result['file_size']     = (int) filesize( $file );
        $result['file_modified'] = wp_date( 'Y-m-d H:i:s', filemtime( $file ) );

        // MMI_Logger truncates this file in-place at a 10MB hard cap (see
        // AGENTS.md's Logging § Log File Policy), so a single read here is
        // always bounded — no separate chunked/tail-seek reading needed for
        // a plugin-triggered, non-automatic action like this one.
        $content = (string) file_get_contents( $file );
        $lines   = array_filter( explode( "\n", $content ), static fn( $l ) => trim( $l ) !== '' );

        $result['total_lines'] = count( $lines );

        $parsed  = [];
        $sources = [];

        foreach ( $lines as $line ) {
            $entry = self::parse_line( $line );
            if ( $entry['source'] !== '' ) {
                $sources[ $entry['source'] ] = true;
            }
            $parsed[] = $entry;
        }

        $result['sources'] = array_values( array_unique( array_keys( $sources ) ) );
        sort( $result['sources'] );

        $filtered = array_values( array_filter( $parsed, static function ( $entry ) use ( $level, $source, $search ) {
            if ( $level !== 'ALL' && $entry['level'] !== $level ) {
                return false;
            }
            if ( $source !== 'all' && $entry['source'] !== $source ) {
                return false;
            }
            if ( $search !== '' && stripos( $entry['raw'], $search ) === false ) {
                return false;
            }
            return true;
        } ) );

        $result['total_matching'] = count( $filtered );
        $result['truncated']      = count( $filtered ) > $limit;
        $result['entries']        = array_slice( $filtered, -$limit );

        return $result;
    }

    /**
     * @return array{ts:string, level:string, source:string, message:string, raw:string}
     */
    private static function parse_line( string $line ): array {
        if ( preg_match( self::LINE_PATTERN, $line, $m ) === 1 ) {
            return [
                'ts'      => $m['ts'],
                'level'   => $m['level'],
                'source'  => $m['source'] ?? '',
                'message' => $m['rest'],
                'raw'     => $line,
            ];
        }

        // A line that doesn't match the expected shape (e.g. a stray write
        // from before this plugin adopted MMI_Logger, or a wrapped
        // multi-line message) is still shown, not silently dropped — just
        // without level/source metadata to filter or color it by.
        return [ 'ts' => '', 'level' => 'OTHER', 'source' => '', 'message' => $line, 'raw' => $line ];
    }

    public static function clear(): bool {
        return MMI_Logger::clear( self::CATEGORY );
    }
}
