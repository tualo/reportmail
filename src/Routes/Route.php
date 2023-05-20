<?php

namespace Tualo\Office\ReportMail\Routes;

use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;
use PHPMailer\PHPMailer\PHPMailer;

class Route implements IRoute
{
    public static function register()
    {


        BasicRoute::add('/reportmail/send', function ($matches) {
            $db = App::get('session')->getDB();




            if ($db->singleRow('select id from cmp_mail_calls where id>date_add(now(),interval -3 minute)')) {
                $db->direct('insert into cmp_mail_calls (id,started,data) values (now(),0,{data}) ', array('data' => print_r($_REQUEST, true)));
            } else {
                $db->direct('insert into cmp_mail_calls (id,started,data) values (now(),1,{data}) ', array('data' => print_r($_REQUEST, true)));

                $bezuege = $db->direct('select base_table,blg_table from bezug_config', array(), 'base_table');
                $belegarten = $db->direct('select id,name,tabellenzusatz,adress_bezug from blg_config');
                foreach ($belegarten as $belegart) {
                    if (isset($bezuege[$belegart['adress_bezug']])) {
                        try {

                            $config = false;
                            try {
                                $sql = 'select * from blg_mailconfig_' . $belegart['tabellenzusatz'];
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
              blg_mailto_#bez_#tz.*
            from
              blg_hdr_#tz
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
              blg_hdr_#tz.datum
            limit 10
            ';
                                    $sql = str_replace('#bez', $bezuege[$belegart['adress_bezug']]['blg_table'], $sql);
                                    $sql = str_replace('#tz', $belegart['tabellenzusatz'], $sql);

                                    $reports = $db->direct($sql);



                                    foreach ($reports as $report_item) {
                                        $mail_txt = $config['txt_template'];
                                        $mail_subject = $config['mail_subject'];

                                        foreach ($report_item as $key => $val) {
                                            $mail_txt = str_replace('{' . $key . '}', $val, $mail_txt);
                                            $mail_subject = str_replace('{' . $key . '}', $val, $mail_subject);
                                        }
                                        if ($mail_txt != "") {

                                            $mail = new PHPMailer();
        
                                            $mail->SMTPDebug = 3;                               // Enable verbose debug output
                                            $mail->CharSet = "utf-8";
                                
                                            $mail->isSMTP();                                      // Set mailer to use SMTP
                                            $mail->Host = $db->singleValue('select getSetup("cmp_mail","SMTP_HOST") v',[],'v');
                                            // $this->getCMPSetup('cmp_mail','SMTP_HOST');  // Specify main and backup SMTP servers
                                            $mail->SMTPAuth = true;                               // Enable SMTP authentication
                                            $mail->Username =  $db->singleValue('select getSetup("cmp_mail","SMTP_USER") v',[],'v');
                                            // $this->getCMPSetup('cmp_mail','SMTP_USER');             // SMTP username
                                            $mail->Password =  $db->singleValue('select getSetup("cmp_mail","SMTP_PASS") v',[],'v');
                                            // $this->getCMPSetup('cmp_mail','SMTP_PASS');                           // SMTP password
                                            $secure =  $db->singleValue('select getSetup("cmp_mail","SMTP_SECURE") v',[],'v');
                                            // $this->getCMPSetup('cmp_mail','SMTP_SECURE');
                                            if ($secure==''){
                                                $mail->SMTPSecure = false;                            // Enable TLS encryption, `ssl` also accepted
                                            }else{
                                                $mail->SMTPSecure = $secure;                            // Enable TLS encryption, `ssl` also accepted
                                            }
                                            $mail->Port = 587;                                    // TCP port to connect to
                                
                                            if  ($db->singleValue('select getSetup("cmp_mail","SMTP_SECURE") v',[],'v')=='1'){
                                            //($this->getCMPSetup('cmp_mail','SMTP_NO_AUTOTLS')=='1'){
                                                $mail->SMTPAutoTLS = false;
                                            }
                                
                                            if  ($db->singleValue('select getSetup("cmp_mail","SMTP_NO_CERT_CHECK") v',[],'v')=='1'){
                                            //if ($this->getCMPSetup('cmp_mail','SMTP_NO_CERT_CHECK')=='1'){
                                                $mail->SMTPOptions = array(
                                                    'ssl' => array(
                                                        'verify_peer' => false,
                                                        'verify_peer_name' => false,
                                                        'allow_self_signed' => true
                                                    )
                                                );
                                            }
                                

                                            if (!isset($config['pdf_attachment'])) {
                                                $config['pdf_attachment'] = 1;
                                            }
                                            if (!isset($report_item['pdf_attachment'])) {
                                                $report_item['pdf_attachment'] = 1;
                                            }

                                            if (!isset($config['excel_attachment'])) {
                                                $config['excel_attachment'] = 0;
                                            }
                                            if (!isset($report_item['excel_attachment'])) {
                                                $report_item['excel_attachment'] = 0;
                                            }




                                            $mail->setFrom($config['mail_from'], $config['mail_from_name']);


                                            $mails = explode(';', $report_item['mailto']);
                                            if (count($mails) > 0) {
                                                foreach ($mails as $value) {
                                                    $mail->addAddress($value);
                                                }
                                            }

                                            $mail->addReplyTo($config['mail_reply'], $config['mail_reply_name']);

                                            $_REQUEST['return'] = 'direkt';
                                            $_REQUEST['belegnummer'] = $report_item['belegnummer'];
                                            $_REQUEST['b'] = $belegart['id'];

                                            if (
                                                ($config['excel_attachment'] == 1) ||  ($report_item['excel_attachment'] == 1)
                                            ) {
                                                /*
                                                include __REAL_PATH__ . '/cmp/cmp_belege/page/report/report.excel.php';
                                                $mail->addAttachment(__REAL_PATH__ . '/temp/' . $this->getParameter("sid") . '/' . $direkt,  $report_item['datum'] . '.xlsx');
                                                if (!file_exists(__REAL_PATH__ . '/temp/' . $this->getParameter("sid") . '/' . $direkt)) {
                                                    exit();
                                                }
                                                */
                                            }

                                            if (
                                                ($config['pdf_attachment'] == 1) && ($report_item['pdf_attachment'] == 1)
                                            ) {
                                               
                                                DomPDFRenderingHelper::render([
                                                    'template'=>'blg_template_2021',
                                                    'id'=>1275127
                                                ],[
                                                    'save'=>App::getTempPath() . '/blg_template_2021.pdf'
                                                ]);
                                                /*
                                                include __REAL_PATH__ . '/cmp/cmp_belege/page/report/report.php';
                                                $fn = explode('-', $report_item['belegnummer']);
                                                foreach ($fn as $f) {
                                                    $mail->addAttachment(__REAL_PATH__ . '/temp/' . $this->getParameter("sid") . '/' . 'BN-' . $f . '.pdf', 'BN-' . $f . '.pdf');
                                                    if (!file_exists(__REAL_PATH__ . '/temp/' . $this->getParameter("sid") . '/' . 'BN-' . $f . '.pdf')) {
                                                        exit();
                                                    }
                                                }*/
                                            }

                                            $mail->Subject = $mail_subject;
                                            $mail->Body    = $mail_txt;

                                            if (!$mail->send()) {
                                                echo 'Message could not be sent.';
                                                echo 'Mailer Error: ' . $mail->ErrorInfo;
                                                exit();
                                            } else {
                                                echo 'Message has been sent';
                                                $fn = explode('-', $report_item['belegnummer']);
                                                foreach ($fn as $f) {
                                                    $sql = 'insert into blg_mail_#tz (id,mailto,sendtime) values ({id},{mailto},now()) on duplicate key update id = values(id)';
                                                    $sql = str_replace('#tz', $belegart['tabellenzusatz'], $sql);
                                                    $hash = $report_item;
                                                    $hash['id'] =  $f;
                                                    $db->execute_with_hash($sql, $hash);
                                                }
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
                    }
                }
            }


            try {
                $db->direct('delete from  cmp_mail_calls where id <date_add(now(),interval -7 day) ;');
            } catch (\Exception $e) {
            }
        }, array('get'), false);
    }
}
