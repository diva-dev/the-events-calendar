<?php
namespace TEC\Sniffs\XSS;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use TEC\Sniff;

/**
 * Squiz_Sniffs_XSS_EscapeOutputSniff.
 *
 * PHP version 5
 *
 * @category PHP
 * @package  PHP_CodeSniffer
 * @author   Weston Ruter <weston@x-team.com>
 */

/**
 * Verifies that all outputted strings are escaped.
 *
 * Blatant copy from WordPress coding standards repo
 *
 * @category PHP
 * @package  PHP_CodeSniffer
 * @author   Weston Ruter <weston@x-team.com>
 * @link     http://codex.wordpress.org/Data_Validation Data Validation on WordPress Codex
 */
class EscapeOutputSniff extends Sniff
{

	/**
	 * Custom list of functions which escape values for output.
	 *
	 * @since 0.5.0
	 *
	 * @var string[]
	 */
	public $customEscapingFunctions = array();

	/**
	 * Custom list of functions whose return values are pre-escaped for output.
	 *
	 * @since 0.3.0
	 *
	 * @var string[]
	 */
	public $customAutoEscapedFunctions = array();

	/**
	 * Custom list of functions which escape values for output.
	 *
	 * @since 0.3.0
	 * @deprecated 0.5.0 Use $customEscapingFunctions instead.
	 *
	 * @var string[]
	 */
	public $customSanitizingFunctions = array();

	/**
	 * Custom list of functions which print output incorporating the passed values.
	 *
	 * @since 0.4.0
	 *
	 * @var string[]
	 */
	public $customPrintingFunctions = array();

	/**
	 * Printing functions that incorporate unsafe values.
	 *
	 * @since 0.4.0
	 *
	 * @var array
	 */
	public static $unsafePrintingFunctions = array(
		'_e' => 'esc_html_e() or esc_attr_e()',
		'_ex' => 'esc_html_ex() or esc_attr_ex()',
	);

	/**
	 * Whether the custom functions were added to the default lists yet.
	 *
	 * @var bool
	 */
	public static $addedCustomFunctions = false;

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @return array
	 */
	public function register()
	{
		return array(
			T_ECHO,
			T_PRINT,
			T_EXIT,
			T_STRING,
		);

	}//end register()


	/**
	 * Processes this test, when one of its tokens is encountered.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  The position of the current token
	 *                        in the stack passed in $tokens.
	 *
	 * @return int|void
	 */
	public function process( File $phpcsFile, $stackPtr )
	{
		// Merge any custom functions with the defaults, if we haven't already.
		if ( ! self::$addedCustomFunctions ) {
			Sniff::$escapingFunctions = array_merge( Sniff::$escapingFunctions, array_flip( $this->customEscapingFunctions ) );
			Sniff::$autoEscapedFunctions = array_merge( Sniff::$autoEscapedFunctions, array_flip( $this->customAutoEscapedFunctions ) );
			Sniff::$printingFunctions = array_merge( Sniff::$printingFunctions, array_flip( $this->customPrintingFunctions ) );

			if ( ! empty( $this->customSanitizingFunctions ) ) {
				Sniff::$escapingFunctions = array_merge( Sniff::$escapingFunctions, array_flip( $this->customSanitizingFunctions ) );
				$phpcsFile->addWarning( 'The customSanitizingFunctions property is deprecated in favor of customEscapingFunctions.', 0, 'DeprecatedCustomSanitizingFunctions' );
			}

			self::$addedCustomFunctions = true;
		}

		$this->init( $phpcsFile );
		$tokens = $phpcsFile->getTokens();

		$function = $tokens[ $stackPtr ]['content'];

		// Find the opening parenthesis (if present; T_ECHO might not have it).
		$open_paren = $phpcsFile->findNext( Tokens::$emptyTokens, $stackPtr + 1, null, true );

		// If function, not T_ECHO nor T_PRINT
		if ( $tokens[$stackPtr]['code'] == T_STRING ) {
			// Skip if it is a function but is not of the printing functions.
			if ( ! isset( self::$printingFunctions[ $tokens[ $stackPtr ]['content'] ] ) ) {
				return;
			}

			if ( isset( $tokens[ $open_paren ]['parenthesis_closer'] ) ) {
				$end_of_statement = $tokens[ $open_paren ]['parenthesis_closer'];
			}

			// These functions only need to have the first argument escaped.
			if ( in_array( $function, array( 'trigger_error', 'user_error' ) ) ) {
				$end_of_statement = $phpcsFile->findEndOfStatement( $open_paren + 1 );
			}
		}

		// Checking for the ignore comment, ex: //xss ok
		if ( $this->has_whitelist_comment( 'xss', $stackPtr ) ) {
			return;
		}

		if ( isset( $end_of_statement, self::$unsafePrintingFunctions[ $function ] ) ) {
			$error = $phpcsFile->addWarning( "Expected next thing to be an escaping function (like %s), not '%s'", $stackPtr, 'UnsafePrintingFunction', array( self::$unsafePrintingFunctions[ $function ], $function ) );

			// If the error was reported, don't bother checking the function's arguments.
			if ( $error ) {
				return $end_of_statement;
			}
		}

		$ternary = false;

		// This is already determined if this is a function and not T_ECHO.
		if ( ! isset( $end_of_statement ) ) {

			$end_of_statement = $phpcsFile->findNext( array( T_SEMICOLON, T_CLOSE_TAG ), $stackPtr );
			$last_token = $phpcsFile->findPrevious( Tokens::$emptyTokens, $end_of_statement - 1, null, true );

			// Check for the ternary operator. We only need to do this here if this
			// echo is lacking parenthesis. Otherwise it will be handled below.
			if ( T_OPEN_PARENTHESIS !== $tokens[ $open_paren ]['code'] || T_CLOSE_PARENTHESIS !== $tokens[ $last_token ]['code'] ) {

				$ternary = $phpcsFile->findNext( T_INLINE_THEN, $stackPtr, $end_of_statement );

				// If there is a ternary skip over the part before the ?. However, if
				// there is a closing parenthesis ending the statement, we only do
				// this when the opening parenthesis comes after the ternary. If the
				// ternary is within the parentheses, it will be handled in the loop.
				if (
					$ternary
					&& (
						T_CLOSE_PARENTHESIS !== $tokens[ $last_token ]['code']
						|| $ternary < $tokens[ $last_token ]['parenthesis_opener']
					)
				) {
					$stackPtr = $ternary;
				}
			}
		}

		// Ignore the function itself.
		$stackPtr++;

		$in_cast = false;

		// looping through echo'd components
		$watch = true;
		for ( $i = $stackPtr; $i < $end_of_statement; $i++ ) {

			// Ignore whitespaces and comments.
			if ( in_array( $tokens[ $i ]['code'], array( T_WHITESPACE, T_COMMENT ) ) ) {
				continue;
			}

			if ( T_OPEN_PARENTHESIS === $tokens[ $i ]['code'] ) {

				if ( $in_cast ) {

					// Skip to the end of a function call if it has been casted to a safe value.
					$i       = $tokens[ $i ]['parenthesis_closer'];
					$in_cast = false;

				} else {

					// Skip over the condition part of a ternary (i.e., to after the ?).
					$ternary = $phpcsFile->findNext( T_INLINE_THEN, $i, $tokens[ $i ]['parenthesis_closer'] );

					if ( $ternary ) {

						$next_paren = $phpcsFile->findNext( T_OPEN_PARENTHESIS, $i, $tokens[ $i ]['parenthesis_closer'] );

						// We only do it if the ternary isn't within a subset of parentheses.
						if ( ! $next_paren || $ternary > $tokens[ $next_paren ]['parenthesis_closer'] ) {
							$i = $ternary;
						}
					}
				}

				continue;
			}

			// Handle arrays for those functions that accept them.
			if ( $tokens[ $i ]['code'] === T_ARRAY ) {
				$i++; // Skip the opening parenthesis.
				continue;
			}

			if ( in_array( $tokens[ $i ]['code'], array( T_DOUBLE_ARROW, T_CLOSE_PARENTHESIS ) ) ) {
				continue;
			}

			// Handle magic constants for debug functions.
			if ( in_array( $tokens[ $i ]['code'], array( T_METHOD_C, T_FUNC_C, T_FILE, T_CLASS_C ) ) ) {
				continue;
			}

			// Wake up on concatenation characters, another part to check
			if ( in_array( $tokens[$i]['code'], array( T_STRING_CONCAT ) ) ) {
				$watch = true;
				continue;
			}

			// Wake up after a ternary else (:).
			if ( $ternary && in_array( $tokens[$i]['code'], array( T_INLINE_ELSE ) ) ) {
				$watch = true;
				continue;
			}

			// Wake up for commas.
			if ( $tokens[ $i ]['code'] === T_COMMA ) {
				$in_cast = false;
				$watch = true;
				continue;
			}

			if ( $watch === false )
				continue;

			// Allow T_CONSTANT_ENCAPSED_STRING eg: echo 'Some String';
			// Also T_LNUMBER, e.g.: echo 45; exit -1;
			if ( in_array( $tokens[$i]['code'], array( T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_MINUS ) ) ) {
				continue;
			}

			$watch = false;

			// Allow int/double/bool casted variables
			if ( in_array( $tokens[$i]['code'], array( T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST ) ) ) {
				$in_cast = true;
				continue;
			}

			// Now check that next token is a function call.
			if ( T_STRING === $this->tokens[ $i ]['code'] ) {

				$functionName = $this->tokens[ $i ]['content'];
				$is_formatting_function = isset( self::$formattingFunctions[ $functionName ] );

				// Skip pointer to after the function.
				if ( $_pos = $this->phpcsFile->findNext( array( T_OPEN_PARENTHESIS ), $i, null, null, null, true ) ) {

					// If this is a formatting function we just skip over the opening
					// parenthesis. Otherwise we skip all the way to the closing.
					if ( $is_formatting_function ) {
						$i     = $_pos + 1;
						$watch = true;
					} else {
						$i = $this->tokens[ $_pos ]['parenthesis_closer'];
					}
				}

				// If this is a safe function, we don't flag it.
				if (
					$is_formatting_function
					|| isset( self::$autoEscapedFunctions[ $functionName ] )
					|| isset( self::$escapingFunctions[ $functionName ] )
				) {
					continue;
				}
			}

			$this->phpcsFile->addWarning(
				"Expected next thing to be an escaping function (see Codex for 'Data Validation'), not '%s'",
				$i,
				'OutputNotEscaped',
				$this->tokens[ $i ]['content']
			);
		}

		return $end_of_statement;

	}//end process()

}//end class
