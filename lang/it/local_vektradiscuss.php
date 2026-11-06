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

$string['button_resume'] = 'Riprendi';
$string['button_start'] = 'Discuti questa prova';
$string['button_start_session'] = 'Discuti questa prova (sessione #{$a})';
$string['error_key_duplicate'] = 'La chiave mint e la chiave docente devono essere due chiavi diverse.';
$string['error_key_is_adminkey'] = 'Questa è la chiave admin del blocco Vektra AI Assistant. Usare una chiave dedicata a questo plugin; una chiave admin non deve mai essere salvata qui.';
$string['error_uiurl_origin'] = 'Inserire solo un\'origine: schema, host e porta opzionale, senza percorso, query o credenziali.';
$string['error_uiurl_scheme'] = 'L\'origine dell\'interfaccia di discussione deve usare https.';
$string['iframe_title'] = 'Discussione della valutazione';
$string['pluginname'] = 'Discussione di valutazione Vektra';
$string['pre_ai_disclosure'] = 'Parlerai con un assistente basato su intelligenza artificiale, non con una persona. Le sue risposte possono contenere errori.';
$string['pre_cancel'] = 'Non ora';
$string['pre_duration'] = 'Durata: {$a} minuti. Se non corrisponde, contatta il docente prima di iniziare.';
$string['pre_enrolment'] = 'L\'accesso alla discussione e alla trascrizione in Moodle dipende dalla tua iscrizione a questo corso: scaricane una copia prima della fine del corso.';
$string['pre_file_partial'] = 'leggibile in parte';
$string['pre_file_readable'] = 'leggibile';
$string['pre_file_unreadable'] = 'non leggibile';
$string['pre_files_heading'] = 'I file consegnati';
$string['pre_files_note'] = 'Se un file importante non può essere letto, potrai discuterne all\'orale.';
$string['pre_limits'] = 'Limiti: al massimo {$a->chars} caratteri per messaggio, {$a->rate} messaggi al minuto.';
$string['pre_material_notused'] = 'Materiale del corso: non usato.';
$string['pre_material_used'] = 'Materiale del corso: usato.';
$string['pre_no_grade_change'] = 'La discussione non modifica il voto.';
$string['pre_one_session'] = 'Hai una sola sessione: una volta avviata non si può ripetere.';
$string['pre_previous_sessions'] = 'Sessioni precedenti su questa prova: {$a}.';
$string['pre_privacy_link'] = 'Informativa privacy';
$string['pre_privacy_summary'] = 'La conversazione è trattata dal servizio di valutazione Vektra e conservata secondo le sue regole di conservazione.';
$string['pre_privacy_version'] = 'versione {$a}';
$string['pre_recipients'] = 'La trascrizione sarà inviata a:';
$string['pre_start'] = 'Inizia';
$string['pre_title'] = 'Prima di iniziare';
$string['pre_transcript_copy'] = 'La trascrizione viene consegnata al docente e ne ricevi una copia identica.';
$string['preparing'] = 'Preparazione della discussione…';
$string['privacy:metadata:vektra_assess'] = 'Il plugin invia al servizio di valutazione Vektra i dati necessari a svolgere e registrare una discussione di valutazione; il servizio li conserva secondo le proprie regole di retention.';
$string['privacy:metadata:vektra_assess:attempt'] = 'Il tentativo di quiz, con risposte, voti e feedback.';
$string['privacy:metadata:vektra_assess:cmid'] = 'L\'id dell\'attività (course module).';
$string['privacy:metadata:vektra_assess:conversation'] = 'I messaggi scambiati dallo studente con l\'assistente di discussione.';
$string['privacy:metadata:vektra_assess:courseid'] = 'L\'id del corso dell\'attività.';
$string['privacy:metadata:vektra_assess:submission'] = 'La consegna dell\'attività compito, con voti, rubrica e feedback.';
$string['privacy:metadata:vektra_assess:transcript'] = 'La trascrizione della discussione consegnata al docente.';
$string['privacy:metadata:vektra_assess:userid'] = 'L\'id utente Moodle dello studente o del docente.';
$string['role_editingteacher'] = 'Docente';
$string['role_manager'] = 'Manager';
$string['role_teacher'] = 'Docente senza permessi di modifica';
$string['settings_apiurl_shared'] = 'URL API Vektra (dal blocco)';
$string['settings_connection'] = 'Connessione';
$string['settings_connection_desc'] = 'L\'indirizzo dell\'API Vektra è condiviso con il blocco Vektra AI Assistant; si modifica nelle impostazioni del blocco.';
$string['settings_enabled'] = 'Abilita le discussioni di valutazione';
$string['settings_enabled_desc'] = 'Interruttore generale del sito. Se disabilitato il plugin non mostra nulla e non fa richieste a Vektra. Lasciare disabilitato finché le chiavi non sono impostate e il controllo di configurazione non è superato.';
$string['settings_keys'] = 'Chiavi di servizio';
$string['settings_keys_desc'] = 'Due chiavi distinte e ristrette, fornite dall\'operatore Vektra. Non usare qui la chiave admin del blocco Vektra AI Assistant e non usare la stessa chiave per i due ruoli.';
$string['settings_mintkey'] = 'Chiave mint (assess:mint)';
$string['settings_mintkey_desc'] = 'Chiave usata per creare e avviare le discussioni degli studenti.';
$string['settings_publicurl_shared'] = 'URL pubblico Vektra (dal blocco)';
$string['settings_shared_link'] = '{$a->value} (<a href="{$a->url}">modifica nelle impostazioni del blocco</a>)';
$string['settings_shared_notset'] = 'Non impostato';
$string['settings_siteid'] = 'Identificativo del sito';
$string['settings_siteid_desc'] = 'L\'identificativo di questo sito Moodle, fornito dall\'operatore Vektra insieme alle chiavi.';
$string['settings_teacherkey'] = 'Chiave docente (assess:teacher)';
$string['settings_teacherkey_desc'] = 'Chiave usata per le pagine docente, il download delle trascrizioni e il controllo di configurazione.';
$string['settings_uiurl'] = 'Origine dell\'interfaccia di discussione';
$string['settings_uiurl_desc'] = 'Facoltativo. Origine (schema, host e porta opzionale, senza percorso) che serve l\'interfaccia di discussione, preferibilmente diversa dall\'origine di Moodle. Se vuoto si usa l\'origine dell\'URL pubblico Vektra. Deve usare https.';
$string['state_active'] = 'Una discussione è in corso.';
$string['state_active_minutes'] = 'Una discussione è in corso: restano {$a} minuti.';
$string['state_closed'] = 'La discussione di questa prova è conclusa.';
$string['state_closed_copy'] = 'Ti consigliamo di scaricare una copia della trascrizione.';
$string['state_closed_date'] = 'La discussione di questa prova si è conclusa il {$a}.';
$string['state_eligible'] = 'Puoi discutere questa prova con l\'assistente.';
$string['state_ended'] = 'Discussione non più disponibile';
$string['state_ended_date'] = 'Discussione non più disponibile dal {$a}';
$string['state_loginas'] = 'Non disponibile in modalità accesso come altro utente';
$string['state_not_enabled'] = 'La discussione di valutazione non è abilitata per questa attività.';
$string['state_not_graded'] = 'Disponibile quando la valutazione sarà pubblicata';
$string['state_not_open'] = 'Disponibile dopo la chiusura definitiva dell\'attività';
$string['state_not_open_attempt_in_progress'] = 'Disponibile quando il tentativo sarà concluso e l\'attività definitivamente chiusa';
$string['state_not_open_close_unreadable'] = 'Non ancora disponibile: la data di chiusura dell\'attività non è verificabile. Contatta il docente.';
$string['state_not_open_date'] = 'Disponibile dal {$a}';
$string['state_not_open_no_close_date'] = 'Disponibile dopo la chiusura definitiva dell\'attività';
$string['state_not_selected'] = 'La discussione riguarda il tentativo #{$a}. Apri quel tentativo per avviarla.';
$string['state_reset_granted'] = 'Il docente ti ha concesso una nuova sessione di discussione.';
$string['state_unavailable'] = 'La discussione è temporaneamente non disponibile. Riprova più tardi.';
$string['student_heading'] = 'Discussione della valutazione';
$string['vektradiscuss:configure'] = 'Configurare la discussione di valutazione per un\'attività';
$string['vektradiscuss:manage'] = 'Gestire le discussioni di valutazione (reset, chiusura, tempo extra, data di apertura, reinvio)';
$string['vektradiscuss:setupcheck'] = 'Eseguire il controllo di configurazione della discussione di valutazione';
$string['vektradiscuss:start'] = 'Avviare una discussione di valutazione sul proprio tentativo';
$string['vektradiscuss:viewowntranscript'] = 'Vedere la trascrizione della propria discussione di valutazione';
$string['vektradiscuss:viewtranscripts'] = 'Vedere e scaricare le trascrizioni delle discussioni di valutazione';
