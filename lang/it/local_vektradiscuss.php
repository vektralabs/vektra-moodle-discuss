<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Italian language strings for local_vektradiscuss.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Discussione di valutazione Vektra';

// Capabilities.
$string['vektradiscuss:configure'] = 'Configurare la discussione di valutazione per un\'attività';
$string['vektradiscuss:manage'] = 'Gestire le discussioni di valutazione (reset, chiusura, tempo extra, data di apertura, reinvio)';
$string['vektradiscuss:setupcheck'] = 'Eseguire il controllo di configurazione della discussione di valutazione';
$string['vektradiscuss:start'] = 'Avviare una discussione di valutazione sul proprio tentativo';
$string['vektradiscuss:viewowntranscript'] = 'Vedere la trascrizione della propria discussione di valutazione';
$string['vektradiscuss:viewtranscripts'] = 'Vedere e scaricare le trascrizioni delle discussioni di valutazione';

// Settings.
$string['settings_enabled'] = 'Abilita le discussioni di valutazione';
$string['settings_enabled_desc'] = 'Interruttore generale del sito. Se disabilitato il plugin non mostra nulla e non fa richieste a Vektra. Lasciare disabilitato finché le chiavi non sono impostate e il controllo di configurazione non è superato.';
$string['settings_connection'] = 'Connessione';
$string['settings_connection_desc'] = 'L\'indirizzo dell\'API Vektra è condiviso con il blocco Vektra AI Assistant; si modifica nelle impostazioni del blocco.';
$string['settings_apiurl_shared'] = 'URL API Vektra (dal blocco)';
$string['settings_publicurl_shared'] = 'URL pubblico Vektra (dal blocco)';
$string['settings_shared_notset'] = 'Non impostato';
$string['settings_shared_link'] = '{$a->value} (<a href="{$a->url}">modifica nelle impostazioni del blocco</a>)';
$string['settings_uiurl'] = 'Origine dell\'interfaccia di discussione';
$string['settings_uiurl_desc'] = 'Facoltativo. Origine (schema, host e porta opzionale, senza percorso) che serve l\'interfaccia di discussione, preferibilmente diversa dall\'origine di Moodle. Se vuoto si usa l\'origine dell\'URL pubblico Vektra. Deve usare https.';
$string['settings_siteid'] = 'Identificativo del sito';
$string['settings_siteid_desc'] = 'L\'identificativo di questo sito Moodle, fornito dall\'operatore Vektra insieme alle chiavi.';
$string['settings_keys'] = 'Chiavi di servizio';
$string['settings_keys_desc'] = 'Due chiavi distinte e ristrette, fornite dall\'operatore Vektra. Non usare qui la chiave admin del blocco Vektra AI Assistant e non usare la stessa chiave per i due ruoli.';
$string['settings_mintkey'] = 'Chiave mint (assess:mint)';
$string['settings_mintkey_desc'] = 'Chiave usata per creare e avviare le discussioni degli studenti.';
$string['settings_teacherkey'] = 'Chiave docente (assess:teacher)';
$string['settings_teacherkey_desc'] = 'Chiave usata per le pagine docente, il download delle trascrizioni e il controllo di configurazione.';
$string['error_key_is_adminkey'] = 'Questa è la chiave admin del blocco Vektra AI Assistant. Usare una chiave dedicata a questo plugin; una chiave admin non deve mai essere salvata qui.';
$string['error_key_duplicate'] = 'La chiave mint e la chiave docente devono essere due chiavi diverse.';
$string['error_uiurl_scheme'] = 'L\'origine dell\'interfaccia di discussione deve usare https.';
$string['error_uiurl_origin'] = 'Inserire solo un\'origine: schema, host e porta opzionale, senza percorso, query o credenziali.';

// Privacy.
$string['privacy:metadata:vektra_assess'] = 'Il plugin invia al servizio di valutazione Vektra i dati necessari a svolgere e registrare una discussione di valutazione; il servizio li conserva secondo le proprie regole di retention.';
$string['privacy:metadata:vektra_assess:userid'] = 'L\'id utente Moodle dello studente o del docente.';
$string['privacy:metadata:vektra_assess:courseid'] = 'L\'id del corso dell\'attività.';
$string['privacy:metadata:vektra_assess:cmid'] = 'L\'id dell\'attività (course module).';
$string['privacy:metadata:vektra_assess:attempt'] = 'Il tentativo di quiz, con risposte, voti e feedback.';
$string['privacy:metadata:vektra_assess:submission'] = 'La consegna dell\'attività compito, con voti, rubrica e feedback.';
$string['privacy:metadata:vektra_assess:conversation'] = 'I messaggi scambiati dallo studente con l\'assistente di discussione.';
$string['privacy:metadata:vektra_assess:transcript'] = 'La trascrizione della discussione consegnata al docente.';
