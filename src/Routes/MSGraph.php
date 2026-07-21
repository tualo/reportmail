<?php

namespace Tualo\Office\ReportMail\Routes;

use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;
use Tualo\Office\MicrosoftMail\MSGraphMail;
use Tualo\Office\RemoteBrowser\RemotePDF;
use Tualo\Office\DS\DSFiles;
use Tualo\Office\DS\DSTable;
use Ramsey\Uuid\Uuid;

class MSGraph implements IRoute /*extends \Tualo\Office\Basic\RouteWrapper*/
{
    public static function register()
    {

        //echo 123;

        BasicRoute::add('/reportmail/project_x', function ($matches) {
            $db = App::get('session')->getDB();
            $local_file_name = '';
            if ($db->singleRow('select id from cmp_mail_calls where id>date_add(now(),interval -3 minute)')) {
                //                $db->direct('insert into cmp_mail_calls (id,started,data) values (now(),0,{data}) ', array('data' => print_r($_REQUEST, true)));
            } else {
                $db->direct('insert into cmp_mail_calls (id,started,data) values (now(),1,{data}) ', array('data' => print_r($_REQUEST, true)));

                $sql = '

                select 
projectmanagement.target_date,
projectmanagement.project_id,
projectmanagement.name,
projectmanagement.client_order_id,
projectmanagement_ansprechpartner.ansprechpartner_id,
projectmanagement_ansprechpartner.anrede,
projectmanagement_ansprechpartner.nachname,
projectmanagement_ansprechpartner.mail,
uebersetzer.vorname uebersetzer_vorname,
uebersetzer.vorname uebersetzer_nachname,
projectmanagement_ansprechpartner_mailed.sendtime
 from 
    projectmanagement 
    join view_readtable_projectmanagement_ansprechpartner projectmanagement_ansprechpartner 
        on projectmanagement_ansprechpartner.project_id = projectmanagement.project_id
    join uebersetzer
        on uebersetzer.kundennummer = projectmanagement.uebersetzer

    left join projectmanagement_ansprechpartner_mailed 
    on (
        projectmanagement_ansprechpartner_mailed.project_id,
        projectmanagement_ansprechpartner_mailed.ansprechpartner_id,
        type
    ) = (
        projectmanagement.project_id,
        projectmanagement_ansprechpartner.ansprechpartner_id,
        "mission_report"
    )
where 
    state = "30005"
    and projectmanagement.projectmanagement_schema = "7b5ccd29-4d68-11ee-bb38-002590c72640"
    and projectmanagement_ansprechpartner.aktiv=1 and projectmanagement_ansprechpartner.mail<>""
    and target_date >= curdate()

having sendtime is null
                
                ';

                $liste = $db->direct($sql);
                foreach ($liste as $item) {


                    $mail = MSGraphMail::get();
                    // $mail->setFrom($config['mail_from'], $config['mail_from_name']);
                    // $mails = explode(';', $report_item['mailto']);
                    // $mail->addReplyTo($config['mail_reply'], $config['mail_reply_name']);
                    $mail->setSubject("Terminzusage - Projekt " . $item['project_name'] . $item['client_order_id']);

                    if (($item['anrede'] == 'Herr')) {
                        $mail->addAddress($item['mail'], $item['vorname'] . ' ' . $item['nachname']);
                    } else {
                        continue;
                    }
                    $mail_txt = 'Sehr geehrte {anrede} {nachname},
 
                    hiermit bestätigen wir Ihnen den Dolmetschereinsatz am {target_date}.
                    Als Dolmetscher erscheint für Sie Herr/Frau {uebersetzer_vorname} {uebersetzer_nachname}.
                    
                    Bei Rückfragen stehe ich Ihnen selbstverständlich gern zur Verfügung.
                    Mit freundlichen Grüßen /With kind regards
                    Ihr World contact Team

                    WORLD Contact Übersetzungsbüro GmbH
                    Büro Gera, Gagarinstraße 15, 07545 Gera
                    Tel. +49 365 2900774
                    Fax. +49 365 2900775
                    Email : info@world-contact.de
                    Internetseite: www.world-contact.de
                ';
                    foreach ($item as $key => $val) {
                        $mail_txt = str_replace('{' . $key . '}', $val, $mail_txt);
                    }
                    $mail->addAddress('thomas.hoffmann@tualo.de');

                    $mail->setBody($mail_txt);
                    $mail->send();
                    $sql = 'insert into projectmanagement_ansprechpartner_mailed (
                    project_id,
                    ansprechpartner_id,
                    type,
                    ) values (
                     {project_id},{ansprechpartner_id},"mission_report",now()) on duplicate key update project_id = values(project_id)';
                    $db->execute_with_hash($sql, $item);
                }
            }
        }, array('get'), false);

        BasicRoute::add('/reportmail/msgraph', function ($matches) {
            $db = App::get('session')->getDB();

            $local_file_name = '';
            if ($db->singleRow('select id from cmp_mail_calls where id>date_add(now(),interval -3 minute)')) {
                //                $db->direct('insert into cmp_mail_calls (id,started,data) values (now(),0,{data}) ', array('data' => print_r($_REQUEST, true)));
            } else {
                // $db->direct('insert into cmp_mail_calls (id,started,data) values (now(),1,{data}) ', array('data' => print_r($_REQUEST, true)));
                //         $bezuege = $db->direct('select base_table,blg_table from bezug_config', array(), 'base_table');
                $belegarten = $db->direct('select id,name,tabellenzusatz,adress_bezug from blg_config');
                foreach ($belegarten as $belegart) {
                    // if (isset($bezuege[$belegart['adress_bezug']])) {
                    try {

                        $config = false;
                        try {
                            $sql = 'select * from blg_mailconfig_' . $belegart['tabellenzusatz'];
                            //         echo $sql;
                            $config = $db->singleRow($sql);
                        } catch (\Exception $e) {
                        }
                        if ($config !== false) {

                            try {


                                $sql = '
                                    select
                                        group_concat(distinct blg_mailto_#bez_#tz.mail_to separator \';\') mailto,
                                        group_concat(distinct blg_hdr_#tz.id separator \'-\') belegnummer,
                                        blg_hdr_#tz.datum,
                                        blg_hdr_#tz.project_id,
                                        blg_hdr_#tz.referenz,
                                        blg_mailto_#bez_#tz.*,
                                        projectmanagement.client_order_id
                                    from
                                        blg_hdr_#tz
                                        left join 
                                            projectmanagement
                                            on projectmanagement.project_id = blg_hdr_#tz.project_id
                                        join blg_#bez_#tz
                                            on blg_#bez_#tz.id = blg_hdr_#tz.id
                                        join blg_mailto_#bez_#tz
                                            on
                                            blg_mailto_#bez_#tz.kundennummer = blg_#bez_#tz.kundennummer
                                            and
                                            (blg_mailto_#bez_#tz.kostenstelle = blg_#bez_#tz.kostenstelle
                                            or blg_#bez_#tz.kostenstelle<>0)
                                        left join blg_mail_#tz
                                            on blg_hdr_#tz.id = blg_mail_#tz.id
                                        
                                        where
                                            blg_hdr_#tz.datum>=\'' . $config['startfrom'] . '\'
                                            and blg_hdr_#tz.datum>= date_add(current_date(), interval -10 day)
                                            and blg_mail_#tz.id is null
                                            and blg_hdr_#tz.create_timestamp < date_add(now(),interval -15 MINUTE) 
                                    group by
                                        blg_mailto_#bez_#tz.kundennummer,
                                         blg_hdr_#tz.id ,
                                        blg_hdr_#tz.datum

                                    limit 1
                                    ';
                                $sql = str_replace('#bez', $belegart['adress_bezug'], $sql);

                                $sql = str_replace('#tz', $belegart['tabellenzusatz'], $sql);


                                $reports = $db->direct($sql);

                                //print_r($reports); exit();

                                foreach ($reports as $report_item) {
                                    $mail_txt = $config['txt_template'];
                                    $mail_subject = $config['mail_subject'];

                                    foreach ($report_item as $key => $val) {
                                        $mail_txt = str_replace('{' . $key . '}', $val, $mail_txt);
                                        $mail_subject = str_replace('{' . $key . '}', $val, $mail_subject);
                                    }
                                    if ($mail_txt != "") {


                                        $mail = MSGraphMail::get();
                                        $mail->setFrom($config['mail_from'], $config['mail_from_name']);
                                        $mails = explode(';', $report_item['mailto']);
                                        /*
                                            if (count($mails) > 0) {
                                                foreach ($mails as $value) {
                                                    $mail->addAddress($value);
                                                }
                                            }
                                                */
                                        $mail->addAddress('thomas.hoffmann@tualo.de');

                                        $mail->addReplyTo($config['mail_reply'], $config['mail_reply_name']);
                                        $mail->setSubject($mail_subject);
                                        $mail->setBody($mail_txt);


                                        $res = RemotePDF::get(
                                            'view_blg_list_' . $belegart['tabellenzusatz'],
                                            $report_item['template'],
                                            $report_item['belegnummer'],
                                            true
                                        );
                                        if (isset($res['filename'])) {
                                            $attachments[] = [
                                                'filename' => basename($res['filename']),
                                                'title' => $res['title'],
                                                'contenttype' => $res['contenttype'],
                                                'filesize' => $res['filesize'],
                                            ];
                                            $attachment_ids[] = basename($res['filename']);
                                            $mail->addAttachment($res['filename'], $res['title'] . '.pdf');



                                            $rec = DSTable::instance('projectmanagement_dokumente')
                                                ->f('project_id', 'eq', $report_item['project_id'])
                                                ->f('typ', 'eq', 'mission_report')
                                                //->read()
                                                ->get();
                                            //echo $report_item['project_id']; exit();
                                            if (($rec !== false) && count($rec) > 0) {

                                                // print_r($rec); 
                                                // exit();
                                                $rec = $rec[0];
                                                $local_file_name = App::get('tempPath') . '/.ht_' . (Uuid::uuid4())->toString();

                                                try {
                                                    $decoded = DSFiles::instance('projectmanagement_dokumente')->getDecoded(
                                                        'id',
                                                        $rec['__id']
                                                    );
                                                    file_put_contents($local_file_name, $decoded);
                                                    echo "----";
                                                    $mail->addAttachment($local_file_name, "Einsatzbericht.pdf");
                                                } catch (\Exception $e) {
                                                    echo $e->getMessage();
                                                }
                                            }
                                        } else {
                                            throw new \Exception('PDF Report konnte nicht erstellt werden');
                                        }
                                        //echo 1; exit();
                                        $mail->send();
                                        $sql = 'insert into blg_mail_#tz (id,mailto,sendtime) values ({belegnummer},{mailto},now()) on duplicate key update id = values(id)';
                                        $sql = str_replace('#tz', $belegart['tabellenzusatz'], $sql);
                                        $hash = $report_item;

                                        //                                       $db->execute_with_hash($sql, $hash);
                                        if ($$local_file_name != '') {
                                            unlink($local_file_name);
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                echo $e->getMessage();
                            }
                        }
                    } catch (\Exception $e) {
                        echo $e->getMessage();
                    }
                    //}
                }
            }


            try {
                $db->direct('delete from  cmp_mail_calls where id <date_add(now(),interval -7 day) ;');
            } catch (\Exception $e) {
            }
        }, array('get'), false);
    }
}
