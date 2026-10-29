<?php
/**
 * File containing the expOdfAiAssist class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * AI assist of the import (expai, opt-in): for an imported document, a summary for the intro and keywords, as
 * suggestions a person applies on the batch page ("Apply"); nothing is changed without that click.
 *
 * Off unless all of these hold: the setting ai.enabled (Document converter page, or odf.ini [AISettings] Enabled),
 * the import asked for it (checkbox on the import page), expai is installed and not switched off, the gate of expai
 * allows the item (its rules by section, class, path and purpose), and the data policy of the provider of the site's
 * default model allows its section and class. Only the document's own text (its first 6000 characters) is sent;
 * every call is recorded in expai's usage log (action ezodf:summary), counts against its budget.
 */
class expOdfAiAssist
{
    const PURPOSE = 'ezodf.summary';

    /** @return bool whether the assist can be offered at all */
    public static function available()
    {
        if ( !expOdfSettings::get( 'ai.enabled' ) || !class_exists( 'expAiConfig' ) || !class_exists( 'expAiUsageRecorder' ) )
            return false;
        try
        {
            return !expAiGate::switchedOff();
        }
        catch ( Throwable $e )
        {
            return false;
        }
    }

    /**
     * Suggestions for one item.
     * @return array( 'summary' => string, 'keywords' => string[], 'model' => string ) or array( 'error' => string )
     */
    public static function suggest( eZContentObject $object, $text )
    {
        if ( !self::available() )
            return array( 'error' => 'AI assist is not available' );
        try
        {
            $config = expAiConfig::instance();
            list( $veto ) = expAiGate::objectVeto( $object, array( 'purpose' => self::PURPOSE ), $config );
            if ( $veto !== '' )
                return array( 'error' => $veto );
            $alias = $config->model();
            $provider = $config->registry()->providerFor( $alias );
            $policy = expAiDataPolicy::forProvider( $config, $provider->providerId() );
            if ( !$policy->meets( $policy->requirement( $object ) ) )
                return array( 'error' => 'the data policy keeps this item away from the model' );
            $main = $object->mainNode();
            $recorder = new expAiUsageRecorder( $provider, array( 'node_id' => $main ? (int)$main->attribute( 'node_id' ) : 0, 'action' => 'ezodf:summary' ) );
            $text = mb_substr( trim( preg_replace( '/\s+/u', ' ', (string)$text ) ), 0, 6000 );
            $answer = $recorder->send( array(
                'system' => 'You write the intro and the keywords of an article that was imported from a document. Answer in the '
                          . 'language of the document. The summary has two or three sentences and no more than 60 words, says what '
                          . 'the document is about, and does not start with "This document". Keywords: three to eight short terms.',
                'messages' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => "Title: " . $object->attribute( 'name' ) . "\n\n" . $text ) ) ) ),
                'max_tokens' => 800,
                'output_schema' => array( 'type' => 'object', 'properties' => array(
                    'summary' => array( 'type' => 'string' ), 'keywords' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
                    'required' => array( 'summary', 'keywords' ), 'additionalProperties' => false ),
            ) );
            $raw = '';
            foreach ( isset( $answer['content'] ) ? (array)$answer['content'] : array() as $block )
                if ( isset( $block['type'], $block['text'] ) && $block['type'] === 'text' )
                    $raw .= $block['text'];
            $data = expAiText::extractJson( expAiText::stripReasoning( $raw ) );
            if ( !is_array( $data ) || !isset( $data['summary'] ) )
                return array( 'error' => 'the answer could not be read' );
            $keywords = array();
            foreach ( isset( $data['keywords'] ) ? (array)$data['keywords'] : array() as $k )
                if ( is_string( $k ) && trim( $k ) !== '' )
                    $keywords[] = expAiText::cut( trim( strip_tags( $k ) ), 60 );
            return array( 'summary' => expAiText::cut( expAiText::oneLine( strip_tags( (string)$data['summary'] ) ), 600 ),
                          'keywords' => array_slice( array_values( array_unique( $keywords ) ), 0, 8 ), 'model' => isset( $answer['model'] ) ? (string)$answer['model'] : $alias );
        }
        catch ( Throwable $e )
        {
            return array( 'error' => expOdfConverter::scrub( $e->getMessage() ) );
        }
    }

    /** @return string the plain text of an item's rich text attributes */
    public static function textOf( eZContentObject $object )
    {
        $out = '';
        foreach ( $object->contentObjectAttributes() as $a )
            if ( $a->attribute( 'data_type_string' ) === 'ezxmltext' && $a->hasContent() )
                $out .= ' ' . strip_tags( str_replace( '><', '> <', (string)$a->attribute( 'data_text' ) ) );
        return html_entity_decode( $out, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    /**
     * Applies suggestions to an item: the summary into its first empty-or-chosen intro-like rich text attribute
     * (intro, short_description, description), the keywords into an ezkeyword attribute; a new version.
     * @return string|null an error, null when applied
     */
    public static function apply( eZContentObject $object, array $suggestion )
    {
        if ( !$object->canEdit() )
            return 'you may not edit this item';
        $dataMap = $object->dataMap();
        $attributes = array();
        foreach ( array( 'intro', 'short_description', 'summary', 'description' ) as $id )
            if ( isset( $dataMap[$id] ) && $dataMap[$id]->attribute( 'data_type_string' ) === 'ezxmltext' && !empty( $suggestion['summary'] ) )
            {
                $attributes[$id] = '<?xml version="1.0" encoding="utf-8"?><section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/"><paragraph>'
                                 . htmlspecialchars( $suggestion['summary'], ENT_XML1 ) . '</paragraph></section>';
                break;
            }
        foreach ( $dataMap as $id => $a )
            if ( $a->attribute( 'data_type_string' ) === 'ezkeyword' && !empty( $suggestion['keywords'] ) )
            {
                $attributes[$id] = implode( ', ', $suggestion['keywords'] );
                break;
            }
        if ( !$attributes )
            return 'the class has no attribute for a summary or keywords';
        $ok = eZContentFunctions::updateAndPublishObject( $object, array( 'attributes' => $attributes ) );
        return $ok ? null : 'the new version could not be published';
    }
}
