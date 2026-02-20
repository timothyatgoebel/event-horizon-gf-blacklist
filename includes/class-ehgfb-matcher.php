<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EH_GFB_Matcher {

    public function match_content( string $value, array $rules ) {
        $value = (string) $value;
        if ( $value === '' ) { return null; }

        foreach ( $rules as $rule_raw ) {
            $rule_raw = trim( (string) $rule_raw );
            if ( $rule_raw === '' ) { continue; }

            $result = $this->match_rule( $value, $rule_raw, 'content' );
            if ( $result ) { return $result; }
        }

        return null;
    }

    public function match_email( string $value, array $rules ) {
        $value = trim( (string) $value );
        if ( $value === '' ) { return null; }

        foreach ( $rules as $rule_raw ) {
            $rule_raw = trim( (string) $rule_raw );
            if ( $rule_raw === '' ) { continue; }

            $result = $this->match_rule( $value, $rule_raw, 'email' );
            if ( $result ) { return $result; }
        }

        return null;
    }

    private function match_rule( string $value, string $rule_raw, string $type ) {
        $value_hash = $this->value_hash( $value );

        // regex: prefix
        if ( stripos( $rule_raw, 'regex:' ) === 0 ) {
            $pattern = trim( substr( $rule_raw, 6 ) );
            if ( $pattern === '' ) { return null; }
            if ( $this->is_valid_regex( $pattern ) && @preg_match( $pattern, $value ) ) {
                return array( 'rule' => $rule_raw, 'value_hash' => $value_hash );
            }
            return null;
        }

        // Email specific helpers
        if ( $type === 'email' ) {
            // Domain wildcard *@domain.com
            if ( strpos( $rule_raw, '*@' ) === 0 ) {
                $domain = strtolower( trim( substr( $rule_raw, 2 ) ) );
                $email  = strtolower( $value );
                if ( $domain !== '' && substr( $email, - ( strlen( $domain ) + 1 ) ) === '@' . $domain ) {
                    return array( 'rule' => $rule_raw, 'value_hash' => $value_hash );
                }
                return null;
            }

            // Exact match (case-insensitive)
            if ( strtolower( $value ) === strtolower( $rule_raw ) ) {
                return array( 'rule' => $rule_raw, 'value_hash' => $value_hash );
            }

            // Basic contains for non-email values (helpful if the field is not type=email)
            if ( stripos( $value, $rule_raw ) !== false ) {
                return array( 'rule' => $rule_raw, 'value_hash' => $value_hash );
            }

            return null;
        }

        // Content rules:
        // If rule looks like a regex delimiter already (e.g. /pattern/i), treat it as regex too.
        if ( $this->looks_like_regex( $rule_raw ) && $this->is_valid_regex( $rule_raw ) ) {
            if ( @preg_match( $rule_raw, $value ) ) {
                return array( 'rule' => 'regex:' . $rule_raw, 'value_hash' => $value_hash );
            }
            return null;
        }

        // Word boundary vs substring
        $has_space = preg_match( '/\s/', $rule_raw );
        $has_nonword = preg_match( '/[^\pL\pN_]/u', $rule_raw );

        if ( $has_space || $has_nonword ) {
            if ( stripos( $value, $rule_raw ) !== false ) {
                return array( 'rule' => $rule_raw, 'value_hash' => $value_hash );
            }
            return null;
        }

        $pattern = '/\b' . preg_quote( $rule_raw, '/' ) . '\b/iu';
        if ( @preg_match( $pattern, $value ) ) {
            return array( 'rule' => $rule_raw, 'value_hash' => $value_hash );
        }

        return null;
    }

    private function looks_like_regex( string $maybe ) : bool {
        // e.g. /foo/i or #foo#i
        if ( strlen( $maybe ) < 3 ) { return false; }
        $delim = $maybe[0];
        if ( ! in_array( $delim, array( '/', '#', '~', '%' ), true ) ) { return false; }
        return ( strrpos( $maybe, $delim ) !== 0 );
    }

    private function is_valid_regex( string $pattern ) : bool {
        // Suppress warnings; just verify compilation.
        return @preg_match( $pattern, '' ) !== false;
    }

    private function value_hash( string $value ) : string {
        $salt = wp_salt( 'auth' );
        return hash_hmac( 'sha256', strtolower( trim( $value ) ), $salt );
    }
}
