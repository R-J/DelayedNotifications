<?php

class NotificationConsolidationPlugin extends Gdn_Plugin
{
    /**
     *  Run on startup to init sane config settings and db changes.
     *
     *  @return void.
     */
    public function setup() {
        if (function_exists('Gdn::config()->touch')) {
            Gdn::config()->touch(
                'Plugin.NotificationConsolidation.Periods',
                '12 hours,1 day,2 days,3 days,4 days,5 days,6 days,1 week'
            );

            Gdn::config()->touch(
                'Plugin.NotificationConsolidation.MinImageSize',
                '20'
            );
        } else {            //try to support Vanilla 2.6...
            touchConfig(
                'Plugin.NotificationConsolidation.Periods',
                '12 hours,1 day,2 days,3 days,4 days,5 days,6 days,1 week'
            );

            touchConfig(
                'Plugin.NotificationConsolidation.MinImageSize',
                '20'
            );
        }
        $this->structure();
    }

    /**
     *  Ensure there is a secret set.
     *
     *  @return void.
     */
    public function structure() {
        $secret = Gdn::get('Plugin.NotificationConsolidation.Secret');
        if ($secret) {
            return;
        }
        Gdn::set(
            'Plugin.NotificationConsolidation.Secret',
            betterRandomString(32, 'aA0')
        );
    }

    /**
     * Dashboard settings page.
     *
     * @param SettingsController $sender instance of the calling class.
     *
     * @return void.
     */
    public function settingsController_notificationConsolidation_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->setHighlightRoute('settings/plugins');

        // Ensure there is a secret url available.
        $this->structure();
        $secret = Gdn::get('Plugin.NotificationConsolidation.Secret');
        $url = $sender->Request->url(
            '/plugin/notificationconsolidation?cron=n&quiet=n&secret='.$secret,
            true
        );
        $periodsarray = explode(',', Gdn::translate(Gdn::config('Plugin.NotificationConsolidation.Periods')));
        // Save period if the form has been posted.
        if ($sender->Form->authenticatedPostBack()) {
            // No need to validate Period as it is a dropdown.
            $period = $sender->Form->getFormValue('Period');
            $maxemail = $sender->Form->getFormValue('Maxemail');
            $extract = $sender->Form->getFormValue('Extract');
            $getimage = $sender->Form->getFormValue('Getimage');
            // Validate Maxemail.
            $sender->Form->validateRule('Maxemail', 'ValidateRequired');
            $sender->Form->validateRule('Maxemail', 'ValidateInteger');
            // Validate the range below
            if ($maxemail < 1 || $maxemail > 300) {
                $sender->Form->adderror(Gdn::translate('enter number between 1 and 300'), 'Maxemail');
            }
            // Validate Extract.
            $sender->Form->validateRule('Extract', 'ValidateRequired');
            $sender->Form->validateRule('Extract', 'ValidateInteger');
            // In case the browser doesn't support min/mx/step attributes we validate the range below
            if ($extract != 0 && ($extract < 30 || $extract > 300)) {
                $sender->Form->adderror(Gdn::translate('enter number between 30 and 300'), 'Extract');
            }
            // Save settings and give feedback.
            if ($sender->Form->errorCount() == 0) {
                Gdn::set('Plugin.NotificationConsolidation.Period', $period);
                Gdn::set('Plugin.NotificationConsolidation.Maxemail', $maxemail);
                Gdn::set('Plugin.NotificationConsolidation.Extract', $extract);
                Gdn::set('Plugin.NotificationConsolidation.Getimage', $getimage);
                if ($period == 0) {
                    Gdn::set('Plugin.NotificationConsolidation.LastRunDate', 0);    //Reset last run
                }
                $sender->informMessage(Gdn::translate('Your changes have been saved.'));
            }
        } else {
            $period = Gdn::get('Plugin.NotificationConsolidation.Period');
            $maxemail = Gdn::get('Plugin.NotificationConsolidation.Maxemail');
            $extract = Gdn::get('Plugin.NotificationConsolidation.Extract');
            $getimage = Gdn::get('Plugin.NotificationConsolidation.Getimage');
        }
        // Prepare content for the view.
        $sender->Form->setValue('Getimage', Gdn::get('Plugin.NotificationConsolidation.Getimage'));//Due to weired Vanilla handlingof checkbox...
        $sender->setData([
            'Title' => Gdn::translate('Notification Consolidation Settings'),
            'Description' => Gdn::translate('This plugin stops the immidate sending of notification emails. Instead, you specify a period after which notifications are sent in a single consolidated email.'),
            'SecretUrl' => anchor($url, $url, ['target'=> '_blank', 'title' => t('Click to open in a new window')]),
            'UrlDescription' => Gdn::translate('You have to create a cron job that periodically polls this url:<br /><code>%s</code>'),
            'ParameterDescription' => Gdn::translate('To silence most messages on cron jobs set cron= parameter to cron=y (in lowercase)'),
            'PeriodDescription' => Gdn::translate('Length of time (period) to accummulate notification before emailing them as a bundle.'),
            'MaxemailDescription' => Gdn::translate('Maximum number of emails to send in one sweep. This is a performance parameter. Specify a number between 1 and 300.'),
            'ExtractDescription' => Gdn::translate('Request that a short content extract be included with the notification. Specify 0 for no extract or an integer between 30 and 300 for extract length.'),
            'GetimageDescription' => Gdn::translate('Indicate that attempt should be made to include small version of referred image in the email notifiction.'),
            'Periodsarray' => $periodsarray,
            'Period' => $period,
            'Maxemail' => $maxemail,
            'Extract' => $extract,
            'Getimage' => $getimage,
            'PeriodLabel' => Gdn::translate('Consolidation Period'),
            'MaxemailLabel' => Gdn::translate('Maxiumun emails'),
            'ExtractLabel' => Gdn::translate('Include extract'),
            'GetimageLabel' => Gdn::translate('Include image')
        ]);

        $sender->render('settings', '', 'plugins/rj-notification-consolidation');
    }

    public function profileController_customNotificationPreferences_handler($sender) {
        // ugly: mixing view and model!
        $attributes = [];
        $period = Gdn::get('Plugin.NotificationConsolidation.Period');
        if ($period == 0) {                     //ignore if disabled
            return;
        }
        $periodsarray = explode(',', Gdn::translate(Gdn::config('Plugin.NotificationConsolidation.Periods')));
        $periodtext = Gdn::translate($periodsarray[$period]);
        $periodmessage = sprintf(
            'Check this box to receive all notification emails consolidated over %s ',
            $periodtext
        );
        if ($sender->Form->authenticatedPostBack()) {
            $sender->UserModel->saveAttribute(
                $sender->User->UserID,
                'NotificationConsolidation',
                $sender->Form->getValue('NotificationConsolidation', false)
            );
        } else {
            if ($sender->User->Attributes['NotificationConsolidation'] ?? false) {
                $attributes = ['checked' => 'checked'];
            }
        }
        echo '<div class="DismissMessage InfoMessage">',
            $periodmessage,
            '</div><div>',
            $sender->Form->checkbox(
                'NotificationConsolidation',
                'Consolidate notification mails',
                $attributes
            ),
            '</div>';
    }

    /**
     * Let notification mails sending fail if user opted for consolidation.
     *
     * @param ActivityModel $sender Instance of the calling class.
     * @param Array $args Event arguments.
     *
     * @return void.
     */
    public function activityModel_beforeSendNotification_handler($sender, $args) {
        $period = Gdn::get('Plugin.NotificationConsolidation.Period', 24);
        if ($period == 0) {                 //Don't delay if consolidation is disabled (period=0)
            return;
        }
        // This will cause an ActivityModel::SENT_SKIPPED status in Activity table.
        if ($args['User']['Attributes']['NotificationConsolidation'] ?? false == true) {
            Gdn::config()->saveToConfig('Garden.Email.Disabled', true, false);
        }
    }
    /**
     * Process accummulated notifications.
     *
     * @param $sender instance of the calling class.
     * @param Array $args Event arguments.
     *
     * @return void.
     */
    public function pluginController_notificationConsolidation_create($sender, $args) {
        $request = $sender->Request->get('secret');
        $force   = ($sender->Request->get('force') == "y");     //force sending consolidated emails (ignoring the period & previous emails. Good for testing)
        $quiet   = ($sender->Request->get('quiet') == "y");     //quiet mode - supress most messages (e.g. for plugin intiated runs)
        $cron    = ($sender->Request->get('cron') == "y");      //cron type runs (some messages are supressed)
        $silence = ($cron || $quiet);                           //Silence MOST messages
        $secret = Gdn::get('Plugin.NotificationConsolidation.Secret');
        // Check if url has been called with the correct key.
        if ($request != $secret) {
            $this->msg('Invalid Parameters', false, true);     // force exception & die
            //
            return;
        }
        if ($force) {
            $this->msg('Forced mode. Last run: '.c('Plugin.NotificationConsolidation.LastforcedRun', '?'), $silence);
            saveToConfig('Plugin.NotificationConsolidation.LastforcedRun', Gdn_Format::toDateTime(Time()));
        }
        // Check if enough time has passed since last run date.
        $period = Gdn::get('Plugin.NotificationConsolidation.Period', '12 hours');
        if ($period == 0) {                                                             //Disabled
            $this->msg('Plugin is disabled when period is set to zero', $quiet);
            return;
        }
        $lastRunDate = Gdn::get('Plugin.NotificationConsolidation.LastRunDate', 0);
        $nexttime = $this->nexttime($period, $lastRunDate);       //Next eligible email consolidation time
        if ($lastRunDate == 0) {            //If this was never set NOW is as good as any time...
            $nexttime = time();
            Gdn::set('Plugin.NotificationConsolidation.LastRunDate', time());
        } elseif ($nexttime >  time()) {                        //Still have more time based on current period
            if ($force) {                                       //However proceed if "force" specified (good for testing)
                $periodsarray = explode(',', Gdn::config('Plugin.NotificationConsolidation.Periods'));
                $goback = end($periodsarray);
                $lastRunDate = strtotime('- '. $goback);        //Simulate "it's time to run"
            } else {
                $this->msg('Still accummulating notices until:'.Gdn_Format::toDateTime($nexttime), $silence);
                return;
            }
        }
        if ($lastRunDate > $nexttime) {                                         //Should never happen
            $this->msg('last run date too high:'.Gdn_Format::toDateTime($lastRunDate));
            Gdn::set('Plugin.NotificationConsolidation.LastRunDate', time());   //Fix by resetting last time
            return;                                                             //but wait for next scheduled run
        }
        // Get _all_ open activities.
        $model = new ActivityModel();
        $unsentActivities = $model->getWhere(
            [
                'Emailed' => $model::SENT_SKIPPED,/*   No need for date filter-- email status is all we need
                'DateInserted > ' => Gdn_Format::toDateTime($lastRunDate)*/
            ],
            'NotifyUserID, DateInserted'
        );
//decho(dbdecode(dbencode($unsentActivities)));
        if (!count($unsentActivities)) {                //No unsents?
            $this->msg('Nothing new to notify', $silence);
            return;                                     //We're all done here
        }
        $this->msg(
            sprintf(
                Gdn::translate('Processing %1$s activities'),
                count($unsentActivities)
            ),
            $silence
        );
        // Group them by user.
        $notifications = [];
        $userModel = Gdn::userModel();
        $extract = Gdn::get('Plugin.NotificationConsolidation.Extract', false);
        $getimage = Gdn::get('Plugin.NotificationConsolidation.Getimage', false);
        $maxemail = Gdn::get('Plugin.NotificationConsolidation.Maxemail', 5);
        $sentcount = 0;                                                     //Count sent emails
        foreach ($unsentActivities as $activity) {
            if (!isset($buttoanchor[$activity['NotifyUserID']])) {
                $buttoanchor[$activity['NotifyUserID']] = $activity['ActivityID'];
            }
            $user = $userModel->getID($activity['NotifyUserID']);
            // Do not proceed if the user has not opted in for a consolidation,
            // is banned or deleted or hasn't logged on for two years.
            if ($user->Banned == true ||
                    $user->Deleted == true ||
                    $user->DateLastActive < Gdn_Format::toDateTime(strtotime('-2 years')) ||
                    $user->Attributes['NotificationConsolidation'] == false
                ) {
                $model->setProperty($activity['ActivityID'], 'Emailed', ActivityModel::SENT_OK);    //These inactives shouldn't be processed again
                continue;
            }
            $notifications[$user->UserID][] = $activity;
        }
        $inqueue = count($notifications);
//decho ($inqueue);
        if (!$inqueue) {                                //No users to notify?
            $this->msg('No users to notify', $silence);
            return;                                     //We're all done here
        }
//decho(dbdecode(dbencode($notifications)));
        // For each user we'll concatenate activities notifications into one message stream
        $messageStream = '';                            //Combined message stream
        $this->msg(
            sprintf(
                Gdn::translate('Processing %1$s users'),
                count($notifications)
            ),
            $silence
        );
        foreach ($notifications as $userID => $activities) {
//decho(dbdecode(dbencode($activities)));
            $streamcount = 0;
            foreach ($activities as $activity) {
                $story = false;
                $skip = false;                      //Few reasons to skip: discussion/comment deleted, originator is the one to be notified...
                $message = '';
                if ($activity['ActivityUserID'] == $activity['NotifyUserID']) {
                    $this->msg('skipping:ActivityUserID = NotifyUserID. id:'.$activity['NotifyUserID']. 'Name:'.$user->Name, $silence);
                    $skip = true;
                }
                if ($activity['NotifyUserID'] == $activity['InsertUserID']) {
                    //$this->msg('skipping:ActivityUserID = InsertUserID. id:'.$activity['NotifyUserID']. 'Name:'.$user->Name , $silence);
                    $skip = true;
                }
                $image = '';
                $extracttext = '';
                $object = $this->getobject($activity);
                if ($object == false) {
                    $skip = true;                               //Presume object was deleted since notification was queued
                } elseif ($object == -1) {                      //Special handling for other notifications
                } else {                                        //Handling of discussion/comment notifications
                }

                if (!$skip) {
                    $message .= $this->formatmessage(
                        $activity['DateInserted'],
                        $activity['Photo'],
                        $object,
                        $getimage,
                        $extract,
                        $this->getHeadline($activity),
                        $story
                    );
                    $messageStream .= wrap($message, 'div'); //accummulate message stream that goes in one email
                    $streamcount += 1;
                }
            }
            //  Send the accummulated messages
            if ($streamcount && $sentcount <= $maxemail) {
//decho (' force:'.$force.' sentcount:'.$sentcount.' inqueue:'.$inqueue.' maxemail:'.$maxemail.' userID:'.$userID);
                if ($this->sendMessage($userID, $messageStream, $buttoanchor[$userID]) == ActivityModel::SENT_OK) {  //successful send?
                    if (!$force) {
                        foreach ($activities as $activity) {                                 //Mark all related activities as emailed
                            $model->setProperty($activity['ActivityID'], 'Emailed', ActivityModel::SENT_OK);
                        }
                        Gdn::set('Plugin.NotificationConsolidation.LastRunDate', time());   //Update last run date to restart period counting
                    }
                }
                $sentcount += 1;
            }
            if ($sentcount == $maxemail) {
                if ($sentcount >= $inqueue) {
                    $endmsg = sprintf(
                        Gdn::translate('%1$s message(s) sent.'),
                        $sentcount,
                    );
                } else {
                    $remaining = $inqueue - $sentcount;
                    $endmsg = sprintf(
                        Gdn::translate('%1$s email message(s) sent (maximum emails per run). %2$s email(s) not sent yet.'),
                        $sentcount,
                        $remaining,
                    );
                }
                $this->msg(
                    $endmsg,
                    $quiet
                );
                return;       //We're all done here
            }
        }
        $this->msg(
            sprintf(
                Gdn::translate('%1$s email messages sent.'),
                $sentcount
            ),
            $quiet
        );
    }
    /**
     * Format individual message.   All formatting is done here since email systems have their own styles and we can't use css.
     * Gmail, Yahoo and other email systems also strip various html tags and attributes forcing the unconventional html style coding below.
     *
     * @param string $date notification related date.
     * @param int $photo originator photo.
     * @param object $object Discussion or Comment object.
     * @param flag $getimage Request to include image.
     * @param string $extract Size of text extract
     * @param string $headline notification headline.
     * @param string $story additional optional text (for non discussions/comment objects)
     *
     * @return string formatted notification.
     */
    private function formatmessage($date, $photo, $object, $getimage, $extract, $headline, $story) {
        // Not counting on css for the resulting email system
        $message = '<table width="98%" cellspacing="0" cellpadding="0" border="0" margin-bottom: 10px;><colgroup><col style="vertical-align: top;"><col></colgroup><tr>';
        if (trim($photo)) {
            $message .= '<td width="26px" valign="top" align="right">' .
                        '<span style="border-radius: 4px;padding: 0px 5px;vertical-align: top;display: table-cell;">'.
                        wrap(
                            '<img src="' . $photo . '" style="width:24px;height:24px;border-radius:4px;" </img>',
                            'span',
                            ['style' => 'display:inline-block;margin:4px;vertical-align: middle;']
                        ) .
                            '</td>';
        }
        $message .= '<td>' . wrap(
            $headline,
            'span',
            ['style'=> 'vertical-align: middle;']
        ) . ' <br>' .  Gdn::translate('on') . ' ' . $date .
                        '</td>';
        $message .= '</tr></table>';

        $prefix = val('Prefix', $object, '');
        if ($prefix) {
            $prefix = wrap(
                $prefix,
                'span',
                ['style' => 'background:darkcyan;color:white;;padding:0px 4px;']
            );
        }
        //
        $message .=  '<span style="display:block;width:98%;white-space:break-spaces;padding-top: 6px;line-height: 1;">' .
                     '<table width="98%" cellspacing="0" cellpadding="0" border="0"><colgroup><col style="vertical-align: top;"><col></colgroup><tr>';
        if ($getimage) {
            $leftcolumn = "78px";
            $image =   $this->getimage($object->Body);      //Try to get embedded image
        } else {
            $leftcolumn = "18px";
        }
        if ($image) {
            $message .= '<td width="' . $leftcolumn . '" valign="top" align="right">' .
                        '<span style="border-radius: 4px;padding: 0px 5px;vertical-align: top;display: table-cell;">'.
                        '<img width="120px" style="display:block; border-radius:6px; border:solid 1px rgba(0,0,0,.08);vertical-align: top;" src="' .
                        $image . '" ></td>';
        } else {
            $message .= '<td width="' . $leftcolumn . '" valign="top" align="right">' .
                        '<span style="border-radius:4px;padding:0px 5px;display: table-cell;">'.
                        ' </td>';
        }
        if ($extract) {                                     //Get content extract
            $extracttext = $this->getextract($object, $extract);
        }
        if ($extracttext) {
            if (isset($object->CommentID)) {
                $commenttext = wrap(
                    Gdn::translate('Comment').':',
                    'span',
                    ['style' => 'background:white;color:#306fa6;padding:0px 4px;text-shadow:1px 0px 0px#0561a6;']
                );
                $message .= '<td style="line-height:1.2;">' . $prefix . ' ' . $commenttext . '<br>' . $extracttext . '</td>';
            } else {
                $message .= '<td style="line-height:1.2;">' . $prefix . '<br>' . $extracttext . '</td>';
            }
        } elseif ($story) {
            $message .= '<td>' . ' ' . $story . '</td>';
        } else {
            $message .= '<td>' . ' </td>';
        }
        $message .= '</tr></table></span>';
        return wrap(
            $message,
            'span',
            ['style' => 'border:3px none #0074d966;border-bottom-style:solid;display:block;width:98%;white-space:break-spaces;padding: 3px 0px;line-height: 1;']
        );
    }
    /**
     * Conditionally issue translated feedback message based on the running environment.
     *
     * @param text $message feedback message.
     * @param flag $quiet quiet mode - no message is displayed if flag is set
     * @param flag $die indicating exception after message is emitted.
     *
     * @return void
     */
    private function msg($text, $quiet = false, $die = false) {
        if ($die) {
            echo '<br>' . "\r\n" . Gdn::translate($text);           //just in case it's a cron job
            throw new NotFoundException('<h4>--- ' . __CLASS__ . '</h4><h3>' . Gdn::translate($text) . ' ---</h3>');
        }
        if ($quiet) {
            return;
        } else {
            echo '<br>' . "\r\n" . Gdn::translate($text);           //Format for both online and cron reporting
        }
    }
   /**
     * Get content object (Discussion or Comment).
     *
     * @param array $activity An activity data record.
     *
     * @return object (or -1 if not discussion/comment, false if not found)
     */
    private function getobject($activity) {
        if ($activity['RecordType'] == 'Discussion') {
            $recordmodel = new DiscussionModel();
        } elseif ($activity['RecordType'] == 'Comment') {
            $recordmodel = new CommentModel();
        } else {
            return -1;
        }
        $object = $recordmodel->GetID($activity['RecordID']);
        if ($object) {
            return $object;
        }
        return false;
    }
   /**
     * Get text extract from Discussion or Comment.
     *
     * @param object $record discussion or comment record.
     * @param int $length extract length
     *
     * @return string
     */
    private function getextract($record, $length) {
        $extracttext = sliceString(preg_replace('/\s+/', ' ', strip_tags($record->Body, '<i><b><br>')), $length);
        return $extracttext;
    }
    /**
     * Calclulate next eligible email notification time based on passed period index nd last run.
     *
     * @param int $period index of period.
     * @param time $lastrun time of last run.
     *
     * @return int time of next eligible run time
     */
    private function nexttime($period, $lastrun) {
        if (!$period) {
            return false;             //zero means disabled
        }
        $periodsarray = explode(',', Gdn::config('Plugin.NotificationConsolidation.Periods'));
        // array must be strtotime eligible...
        //  e.g. 2 hours,6 hours,12 hours,24 hours,2 days,3 days,4 days,5 days,6 days,1 week
        $datetime = new DateTime();
        $datetime->setTimestamp($lastrun);
        $datetime->modify('+' . $periodsarray[$period]);     //Next eligible time
        $nexttime = strtotime($datetime->format('Y-m-d H:i:s'));
        return ($nexttime);
    }

    /**
     * Format the headline of an activity.
     *
     * Adopted from the ActivityModel.
     *
     * @param array $activity An activity data record.
     *
     * @return string The formatted activity headline.
     */
    private function getHeadline($activity) {
        if ($activity['HeadlineFormat']) {
            $activity['Url'] = externalUrl($activity['Route'] ?? '/');
            $activity['Data'] = dbdecode($activity['Data']);
            return formatString(
                $activity['HeadlineFormat'],
                $activity
            );
        }

        if (!isset($activity['ActivityGender'])) {
            $activityType = $model->getActivityType($activity['ActivityTypeID']);
            $data = [$activity];
            $model->joinUsers($data);
            $activity = $data[0];
            $activity['RouteCode'] = $activityType['RouteCode'] ?? '/';
            $activity['FullHeadline'] = $activityType['FullHeadline'] ?? '';
            $activity['ProfileHeadline'] = $activityType['ProfileHeadline'] ?? '';
            $activity['Headline'] = Gdn_Format::activityHeadline(
                $activity,
                '',
                $activity['NotifyUserID']
            );
            return Gdn_Format::activityHeadline(
                $activity,
                '',
                $activity['NotifyUserID']
            );
        }
        return '';
    }

    /**
     * Send consolidated notifications message to the user.
     *
     * Copied in most parts from the ActivityModel.
     *
     * @param int $recipient UserID ID of the user.
     * @param array $messages The messages to be sent.
     * @param string $buttoanchor optional activityID for anchoring template button.
     *
     * @return int One of ActivityModel SENT status.
     */
    private function sendMessage($recipientUserID, $messages, $buttoanchor = '') {
        // Prepare mail
        $actionUrl = Gdn::request()->url('/profile/notifications', true);
        $user = Gdn::userModel()->getID($recipientUserID);
        $lastRunDate = Gdn_Format::toDateTime(Gdn::get('Plugin.NotificationConsolidation.LastRunDate', 0));
        $email = new Gdn_Email();
        $period = Gdn::get('Plugin.NotificationConsolidation.Period');
        $periodsarray = explode(',', Gdn::translate(Gdn::config('Plugin.NotificationConsolidation.Periods')));
        $periodtext = Gdn::translate($periodsarray[$period]);
        $email->subject(
            sprintf(
                Gdn::translate('[%1$s] %2$s'),
                Gdn::config('Garden.Title'),
                sprintf(
                    Gdn::translate('NotificationConsolidation.EmailSubject'),
                    $lastRunDate,
                    $periodtext
                )
            )
        );
        $email->to($user);
        if ($buttoanchor) {
            $actionUrl .= "#Activity_" . $buttoanchor;
        }
        $emailTemplate = $email->getEmailTemplate()
            ->setButton($actionUrl, Gdn::translate('Check out your notifications'))
            ->setTitle(
                wrap(
                    sprintf(
                        Gdn::translate('NotificationConsolidation.EmbeddedTitle'),
                        $lastRunDate,
                        $periodtext
                    ),
                    'span',
                    ['style' => 'font-size: 0.5em;']
                )
            )
            ->setMessage($messages, true);
        $this->EventArguments['Messages'] = $email;
        $this->EventArguments['Email'] = $email;
        $this->fireEvent('BeforeSendNotificationConsolidation');

        try {
            $email->send();
            $emailed = ActivityModel::SENT_OK;
        } catch (phpmailerException $pex) {
            if ($pex->getCode() == PHPMailer::STOP_CRITICAL && !$email->PhpMailer->isServerError($pex)) {
                $emailed = ActivityModel::SENT_FAIL;
            } else {
                $emailed = ActivityModel::SENT_ERROR;
            }
        } catch (Exception $ex) {
            switch ($ex->getCode()) {
                case Gdn_Email::ERR_SKIPPED:
                    $emailed = ActivityModel::SENT_SKIPPED;
                    break;
                default:
                    $emailed = ActivityModel::SENT_FAIL; // similar to http 5xx
            }
        }
        return $emailed;
    }
    /**
* Return first embedded image in dicussion/comment body.
*
* @param object $body standard
*
* @return string html to include image in notification (or empty string)
*/
    public function getimage($body) {
        $i = stripos($body, "<img");
        if ($i === false) {
            return '';
        }        //
        $image = substr($body, $i+4);
        $i = stripos($image, ">");
        if ($i === false) {
            return '';
        }
        $image = substr($image, 0, $i);
        //
        $imageurl = $image;
        $i = stripos($imageurl, "src=");
        if ($i === false) {
            return '';
        }
        $imageurl = substr($imageurl, $i+4);
        $delimiter = substr($imageurl, 0, 1);
        if ($delimiter == '"' or $delimiter == "'") {
            $imageurl = substr($imageurl, 1);
            $i = stripos($imageurl, $delimiter);
            if ($i>0) {
                $imageurl = substr($imageurl, 0, $i);
            }
        } else {
            return '';          //Can't trust local references in remote email system
        }
        $size=getimagesize($imageurl);
        //Ignore smallimages (oftentimes "like"-like buttons)
        $minImageSize = Gdn::config('Plugin.NotificationConsolidation.MinImageSize', "20");
        if ($size[0] < $minImageSize || $size[1] < $minImageSize) {
            return '';
        }
        return $imageurl;
    }
}
