<?php
class DelayedNotificationsPlugin extends Gdn_Plugin {
    /**
     *  Run on startup to init sane config settings and db changes.
     *
     *  @return void.
     */
    public function setup() {
        $this->structure();
    }

    /**
     *  Ensure there is a secret set.
     *
     *  @return void.
     */
    public function structure() {
        touchConfig(
            'Plugins.DelayedNotifications.Periods',
            '12 hours,1 day,2 days,3 days,4 days,5 days,6 days,1 week'
        );

        touchConfig(
            'Plugins.DelayedNotifications.MinImageSize',
            '20'
        );

        $secret = Gdn::get('Plugin.DelayedNotifications.Secret');
        if ($secret) {
            return;
        }
        Gdn::set(
            'Plugin.DelayedNotifications.Secret',
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
    public function settingsController_delayedNotifications_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->setHighlightRoute('settings/plugins');

        // Ensure there is a secret url available.
        $this->structure();
        $secret = Gdn::get('Plugin.DelayedNotifications.Secret');
        $url = $sender->Request->url(
            '/plugin/DelayedNotifications?cron=n&quiet=n&secret='.$secret,
            true
        );
        $periodsArray = explode(',', Gdn::translate(Gdn::config('Plugins.DelayedNotifications.Periods')));
        // Save period if the form has been posted.
        if ($sender->Form->authenticatedPostBack()) {
            // No need to validate Period as it is a dropdown.
            $period = $sender->Form->getFormValue('Period');
            $maxEmail = $sender->Form->getFormValue('MaxEmail');
            $extract = $sender->Form->getFormValue('Extract');
            $getImage = $sender->Form->getFormValue('GetImage');
            // Validate MaxEmail.
            $sender->Form->validateRule('MaxEmail', 'ValidateRequired');
            $sender->Form->validateRule('MaxEmail', 'ValidateInteger');
            // Validate the range below
            if ($maxEmail < 1 || $maxEmail > 300) {
                $sender->Form->addError(Gdn::translate('enter number between 1 and 300'), 'MaxEmail');
            }
            // Validate Extract.
            $sender->Form->validateRule('Extract', 'ValidateRequired');
            $sender->Form->validateRule('Extract', 'ValidateInteger');
            // In case the browser doesn't support min/mx/step attributes we validate the range below
            if ($extract != 0 && ($extract < 30 || $extract > 300)) {
                $sender->Form->addError(Gdn::translate('enter number between 30 and 300'), 'Extract');
            }
            // Save settings and give feedback.
            if ($sender->Form->errorCount() == 0) {
                Gdn::set('Plugin.DelayedNotifications.Period', $period);
                Gdn::set('Plugin.DelayedNotifications.MaxEmail', $maxEmail);
                Gdn::set('Plugin.DelayedNotifications.Extract', $extract);
                Gdn::set('Plugin.DelayedNotifications.GetImage', $getImage);
                if ($period == 0) {
                    Gdn::set('Plugin.DelayedNotifications.LastRunDate', 0);    //Reset last run
                }
                $sender->informMessage(Gdn::translate('Your changes have been saved.'));
            }
        } else {
            $period = Gdn::get('Plugin.DelayedNotifications.Period');
            $maxEmail = Gdn::get('Plugin.DelayedNotifications.MaxEmail');
            $extract = Gdn::get('Plugin.DelayedNotifications.Extract');
            $getImage = Gdn::get('Plugin.DelayedNotifications.GetImage');
        }
        // Prepare content for the view.
        $sender->Form->setValue('GetImage', Gdn::get('Plugin.DelayedNotifications.GetImage'));//Due to weired Vanilla handlingof checkbox...
        $sender->setData([
            'Title' => Gdn::translate('Notification Consolidation Settings'),
            'Description' => Gdn::translate('This plugin stops the immidate sending of notification emails. Instead, you specify a period after which notifications are sent in a single consolidated email.'),
            'SecretUrl' => anchor($url, $url, ['target'=> '_blank', 'title' => t('Click to open in a new window')]),
            'UrlDescription' => Gdn::translate('You have to create a cron job that periodically polls this url:<br /><code>%s</code>'),
            'ParameterDescription' => Gdn::translate('To silence most messages on cron jobs set cron= parameter to cron=y (in lowercase)'),
            'PeriodDescription' => Gdn::translate('Length of time (period) to accummulate notification before emailing them as a bundle.'),
            'MaxEmailDescription' => Gdn::translate('Maximum number of emails to send in one sweep. This is a performance parameter. Specify a number between 1 and 300.'),
            'ExtractDescription' => Gdn::translate('Request that a short content extract be included with the notification. Specify 0 for no extract or an integer between 30 and 300 for extract length.'),
            'GetImageDescription' => Gdn::translate('Indicate that attempt should be made to include small version of referred image in the email notifiction.'),
            'PeriodsArray' => $periodsArray,
            'Period' => $period,
            'MaxEmail' => $maxEmail,
            'Extract' => $extract,
            'GetImage' => $getImage,
            'PeriodLabel' => Gdn::translate('Consolidation Period'),
            'MaxEmailLabel' => Gdn::translate('Maxiumun emails'),
            'ExtractLabel' => Gdn::translate('Include extract'),
            'GetImageLabel' => Gdn::translate('Include image')
        ]);

        $sender->render('settings', '', 'plugins/DelayedNotifications');
    }
    /**
     * Profile notification setting for combined notifications.
     *
     * @param PorfileController $sender instance of the calling class.
     *
     * @return void.
     */
    public function profileController_customNotificationPreferences_handler($sender) {
        // ugly: mixing view and model!
        $attributes = [];
        $period = Gdn::get('Plugin.DelayedNotifications.Period', -1);
        $periodsArray = explode(',', Gdn::translate(Gdn::config('Plugins.DelayedNotifications.Periods')));
        $periodText = Gdn::translate($periodsArray[$period]);
        if ($periodText == '') {      //ignore if disabled
            return;
        }
        $periodmessage = sprintf(
            'Check this box to receive all notification emails consolidated over %s ',
            $periodText
        );
        if ($sender->Form->authenticatedPostBack()) {
            $sender->UserModel->saveAttribute(
                $sender->User->UserID,
                'DelayedNotifications',
                $sender->Form->getValue('DelayedNotifications', false)
            );
        } else {
            if ($sender->User->Attributes['DelayedNotifications'] ?? false) {
                $attributes = ['checked' => 'checked'];
            }
        }
        echo '<div class="DismissMessage InfoMessage">',
            $periodmessage,
            '</div><div>',
            $sender->Form->checkbox(
                'DelayedNotifications',
                'Consolidate notification emails',
                $attributes
            ),
            '</div>';
    }

    /**
     * Let notification mails sending be skipped if user opted for consolidation.
     *
     * @param ActivityModel $sender Instance of the calling class.
     * @param Array $args Event arguments.
     *
     * @return void.
     */
    public function activityModel_beforeSendNotification_handler($sender, $args) {
        $period = Gdn::get('Plugin.DelayedNotifications.Period', 24);
        $periodsArray = explode(',', Gdn::translate(Gdn::config('Plugins.DelayedNotifications.Periods')));
        $periodText = Gdn::translate($periodsArray[$period]);
        if ($periodText == '') {        //Don't delay if consolidation is disabled (period=0)
            return;
        }
        // This will cause an ActivityModel::SENT_SKIPPED status in Activity table.
        if ($args['User']['Attributes']['DelayedNotifications'] ?? false == true) {
            Gdn::config()->saveToConfig('Garden.Email.Disabled', true, false);      //in-memory email disabling
        }
    }
    /**
     * Process accummulated notifications.
     *
     * @param object $sender instance of the calling class.
     * @param Array $args Event arguments.
     *
     * @return void.
     */
    public function pluginController_delayedNotifications_create($sender, $args) {
        $request = $sender->Request->get('secret');
        $force   = ($sender->Request->get('force') == "y");     //force sending emails (ignoring the period & previous emails.For testing)
        $quiet   = ($sender->Request->get('quiet') == "y");     //quiet mode - supress most messages (e.g. for plugin intiated runs)
        $cron    = ($sender->Request->get('cron') == "y");      //cron type runs (some messages are supressed)
        $silence = ($cron || $quiet);                           //Silence MOST messages
        $secret  = Gdn::get('Plugin.DelayedNotifications.Secret');
        // Check if url has been called with the correct key.
        if ($request != $secret) {
            $this->msg(Gdn::translate('Invalid Parameters'), false, true);     // force exception & die
            //
            return;
        }
        if ($force) {
            $this->msg(Gdn::translate('Forced mode. Last run: ') . Gdn::config('Plugins.DelayedNotifications.LastforcedRun', '?'), $silence);
            saveToConfig('Plugins.DelayedNotifications.LastforcedRun', Gdn_Format::toDateTime(Time()));
        }
        // Check if enough time has passed since last run date.
        $period = Gdn::get('Plugin.DelayedNotifications.Period', '12 hours');
        $periodsArray = explode(',', Gdn::translate(Gdn::config('Plugins.DelayedNotifications.Periods')));
        $periodText = Gdn::translate($periodsArray[$period]);
        if ($periodText == '') {      //ignore if disabled
            $this->msg(Gdn::translate('Plugin is disabled when period is set to zero'), $quiet);
            return;
        }
        $lastRunDate = Gdn::get('Plugin.DelayedNotifications.LastRunDate', 0);
        $nextTime = $this->nextTime($period, $lastRunDate);       //Next eligible email consolidation time
        if ($lastRunDate == 0) {            //If this was never set NOW is as good as any time...
            $nextTime = time();
            Gdn::set('Plugin.DelayedNotifications.LastRunDate', time());
        } elseif ($nextTime >  time()) {                        //Still have more time based on current period
            if ($force) {                                       //However proceed if "force" specified (good for testing)
                $goback = end($periodsArray);
                $lastRunDate = strtotime('- '. $goback);        //Simulate "it's time to run"
            } else {
                $this->msg(Gdn::translate('Still accummulating notices until:') . Gdn_Format::toDateTime($nextTime), $silence);
                return;
            }
        }
        if ($lastRunDate > $nextTime) {                                         //Should never happen
            $this->msg(Gdn::translate('last run date too high:') . Gdn_Format::toDateTime($lastRunDate));
            Gdn::set('Plugin.DelayedNotifications.LastRunDate', time());   //Fix by resetting last time
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
            $this->msg(Gdn::translate('Nothing new to notify'), $silence);
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
        $extract = Gdn::get('Plugin.DelayedNotifications.Extract', false);
        $getImage = Gdn::get('Plugin.DelayedNotifications.GetImage', false);
        $maxEmail = Gdn::get('Plugin.DelayedNotifications.MaxEmail', 5);
        $sentCount = 0;                                                     //Count sent emails
        foreach ($unsentActivities as $activity) {
            if (!isset($buttonAnchor[$activity['NotifyUserID']])) {
                $buttonAnchor[$activity['NotifyUserID']] = $activity['ActivityID'];
            }
            $user = $userModel->getID($activity['NotifyUserID']);
            // Do not proceed if the user has not opted in for a consolidation,
            // is banned or deleted or hasn't logged on for two years.
            if ($user->Banned == true ||
                    $user->Deleted == true ||
                    $user->DateLastActive < Gdn_Format::toDateTime(strtotime('-2 years')) ||
                    $user->Attributes['DelayedNotifications'] == false
                ) {
                $model->setProperty($activity['ActivityID'], 'Emailed', ActivityModel::SENT_OK);    //These inactives shouldn't be processed again
                continue;
            }
            $notifications[$user->UserID][] = $activity;
        }
        $inQueue = count($notifications);
//decho ($inQueue);
        if (!$inQueue) {                                //No users to notify?
            $this->msg(Gdn::translate('No users to notify'), $silence);
            return;                                     //We're all done here
        }
//decho(dbdecode(dbencode($notifications)));
        // For each user we'll concatenate activities notifications into one message stream
        $messageStream = '';                            //Combined message stream
        $this->msg(
            sprintf(
                Gdn::translate('Processing %1$s users'),
                $inQueue
            ),
            $silence
        );
        foreach ($notifications as $userID => $activities) {
//decho(dbdecode(dbencode($activities)));
            //Extract category view permissions array (one time for all discussion/comment type notifications for the notified user)
            $userPermissions = dbdecode(dbencode(Gdn::userModel()->getPermissions($userID)))["permissions"]["discussions.view"];
            //
            $streamCount = 0;
            foreach ($activities as $activity) {
                $story = false;
                $skip = false;                      //Few reasons to skip: discussion/comment deleted, originator is the one to be notified...
                $message = '';
                if ($activity['ActivityUserID'] == $activity['NotifyUserID']) {
                    $this->msg(Gdn::translate('skipping:ActivityUserID = NotifyUserID. id:') .
                                $activity['NotifyUserID'] . Gdn::translate('Name:').$user->Name, $silence);
                    $skip = true;
                }
                if ($activity['NotifyUserID'] == $activity['InsertUserID']) {
                    //$this->msg('skipping:ActivityUserID = InsertUserID. id:'.$activity['NotifyUserID']. 'Name:'.$user->Name , $silence);
                    $skip = true;
                }
                $image = '';
                $extractText = '';
                $object = $this->getObject($activity, $userPermissions);
                if ($object == false) {
                    $skip = true;                               //Presume object was deleted since notification was queued
                    $model->setProperty($activity['ActivityID'], 'Emailed', ActivityModel::SENT_OK);    //This shouldn't be processed again
                } elseif ($object == -2) {                      //Notified user has no view permission so don't notify
                    $skip = true;
                } elseif ($object == -1) {                      //Special handling for other notifications
                    $photo = $activity['Photo'];
                } else {                                        //Handling of discussion/comment notifications
                    $photo = Gdn::userModel()->getID($activity['InsertUserID'])->Photo;
                    if ($photo && !isUrl($photo)) {
                        $photo = Gdn_Upload::url(changeBasename($photo, 'n%s'));
                    }
                    if ($photo && isUrl($photo)) {
                    } else {
                        $photo = userPhotoDefaultUrl(Gdn::userModel()->getID($activity['InsertUserID']));  //Suppport avatars;
                    }
                }
                if (!$skip) {
                    $message .= $this->formatMessage(
                        $activity['DateInserted'],
                        $photo,
                        $object,
                        $getImage,
                        $extract,
                        $this->getHeadline($activity),
                        $story
                    );
                    $messageStream .= wrap($message, 'div'); //accummulate message stream that goes in one email
                    $streamCount += 1;
                }
            }
            //  Send the accummulated messages
            if ($streamCount && $sentCount <= $maxEmail) {
//decho (' force:'.$force.' sentcount:'.$sentCount.' inqueue:'.$inQueue.' maxemail:'.$maxEmail.' userID:'.$userID);
                if ($this->sendMessage($userID, $messageStream, $buttonAnchor[$userID]) == ActivityModel::SENT_OK) {  //successful send?
                    if (!$force) {
                        foreach ($activities as $activity) {                                 //Mark all related activities as emailed
                            $model->setProperty($activity['ActivityID'], 'Emailed', ActivityModel::SENT_OK);
                        }
                        Gdn::set('Plugin.DelayedNotifications.LastRunDate', time());   //Update last run date to restart period counting
                    }
                }
                $sentCount += 1;
            }
            if ($sentCount == $maxEmail) {
                if ($sentCount >= $inQueue) {
                    $endmsg = sprintf(
                        Gdn::translate('%1$s message(s) sent.'),
                        $sentCount,
                    );
                } else {
                    $remaining = $inQueue - $sentCount;
                    $endmsg = sprintf(
                        Gdn::translate('%1$s email message(s) sent (maximum emails per run). %2$s email(s) not sent yet.'),
                        $sentCount,
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
                $sentCount
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
     * @param flag $getImage Request to include image.
     * @param string $extract Size of text extract
     * @param string $headline notification headline.
     * @param string $story additional optional text (for non discussions/comment objects)
     *
     * @return string formatted notification.
     */
    private function formatMessage($date, $photo, $object, $getImage, $extract, $headline, $story) {
        // Not counting on css for the resulting email system
        $message = '<table width="98%" cellspacing="0" cellpadding="0" border="0" margin-bottom: 10px;><colgroup><col style="vertical-align: top;"><col></colgroup><tr>';
        if (trim($photo) && substr($photo, 0, 4) == 'http') {
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
        if ($getImage) {
            $leftcolumn = "78px";
            $image =   $this->getImage($object->Body);      //Try to get embedded image
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
            $extractText = $this->getExtract($object->Body, $extract);
        }
        if ($extractText) {
            if (isset($object->CommentID)) {
                $commentText = wrap(
                    Gdn::translate('Comment').':',
                    'span',
                    ['style' => 'background:white;color:#306fa6;padding:0px 4px;text-shadow:1px 0px 0px#0561a6;']
                );
                $message .= '<td style="line-height:1.2;">' . $prefix . ' ' . $commentText . '<br>' . $extractText . '</td>';
            } else {
                $message .= '<td style="line-height:1.2;">' . $prefix . '<br>' . $extractText . '</td>';
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
     * @param text $text feedback message.
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
     * @param array $activity an activity data record.
     * @param array $userPermissions user category access permissions.
     *
     * @return object (or -1 if not discussion/comment, -2 if no access, false if not found)
     */
    private function getObject($activity, $userPermissions) {
        $discussionModel = new DiscussionModel();
        if ($activity['RecordType'] == 'Discussion') {
            $discussion = $discussionModel->getID($activity['RecordID']);
            if (!$discussion) {                     //discussion deleted was after it was created
                return false;                       //indicate not available
            }
            if (!in_array($discussion->CategoryID, $userPermissions)) { //User not allowed to where discussion currently resides
                return -2;                         //indicate not accessible
            }
            return $discussion;
        } elseif ($activity['RecordType'] == 'Comment') {
            $commentModel = new CommentModel();
            $comment = $commentModel->getID($activity['RecordID']);
            if (!$comment) {                        //comment deleted was after it was created
                return false;                       //indicate not available
            }
            //
            $discussion = $discussionModel->getID($comment->DiscussionID);   //Get comment parent discussion
            if (!$discussion) {                     //unlikely model inconsistency (defensive programming...)
                return false;                       //indicate not available
            }
            if (!in_array($discussion->CategoryID, $userPermissions)) { //User not allowed to where discussion currently resides
                return -2;                         //indicate not accessible
            }
            return $comment;
        } else {
            return -1;
        }
    }
   /**
     * Get text extract from Discussion or Comment.
     *
     * @param string $string discussion or comment body text.
     * @param int $length extract length
     *
     * @return string
     */
    private function getExtract($string, $length) {
        $string = $this->deleteBetweenTags('<div class="Spoiler">', '</div>', $string, ' ... ', true); //Replace text within spoiler tags with " ... "
        $extractText = sliceString(preg_replace('/\s+/', ' ', strip_tags($string, '<i><b><br>')), $length);
        $virtualEnd = Gdn::config('Plugins.Extract.virtualEnd', '');
        if ($virtualEnd) {                                              //Extract plugin set content virtual end (tag to stop extract?)?
            $extractText = explode($virtualEnd, $extractText)[0];       //So truncate to that point
        }
        return $extractText;
    }
   /**
     * Remove all text between specified tags.
     *
     * @param string $starttag Starting tag.
     * @param string $endtag end tag.
     * @param string $string string from which to extract text between tags
     * @param string $replace optional string to replace extracted text between tags
     * @param flag $all optional indicator whether to remove all occurences or just the first one
     *
     * @return string
     */
    private function deleteBetweenTags($starttag, $endtag, $string, $replace = '', $all = false) {
        $startPos = strpos($string, $starttag);
        if ($startPos === false) {
            return $string;
        }
        $endPos   = strpos($string, $endtag);
        if ($endPos === false) {
            $endPos = strlen($string);
        } else {
            $endPos = $endPos + strlen($endtag);
        }
        $result = substr($string, 0, $startPos) . $replace . substr($string, ($endPos));         //Mark removed content with replacement
        if ($all) {
            return $this->deleteBetweenTags($starttag, $endtag, $result, $replace, $all);       // recursion to replace all occurences
        } else {
            return $result;
        }
    }
    /**
     * Calclulate next eligible email notification time based on passed period index nd last run.
     *
     * @param int $period index of period.
     * @param time $lastRun time of last run.
     *
     * @return int time of next eligible run time
     */
    private function nextTime($period, $lastRun) {
        $periodsArray = explode(',', Gdn::translate(Gdn::config('Plugins.DelayedNotifications.Periods')));
        $periodText = Gdn::translate($periodsArray[$period]);
        if ($periodText == '') {      //ignore if disabled
            return false;             //zero means disabled
        }
        // array must be strtotime eligible...
        //  e.g. 2 hours,6 hours,12 hours,24 hours,2 days,3 days,4 days,5 days,6 days,1 week
        $datetime = new DateTime();
        $datetime->setTimestamp($lastRun);
        $datetime->modify('+' . $periodsArray[$period]);     //Next eligible time
        $nextTime = strtotime($datetime->format('Y-m-d H:i:s'));
        return ($nextTime);
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
     * @param int $recipientUserID UserID ID of the user.
     * @param array $messages The messages to be sent.
     * @param string $buttonAnchor optional activityID for anchoring template button.
     *
     * @return int One of ActivityModel SENT status.
     */
    private function sendMessage($recipientUserID, $messages, $buttonAnchor = '') {
        // Prepare mail
        $actionUrl = Gdn::request()->url('/profile/notifications', true);
        $user = Gdn::userModel()->getID($recipientUserID);
        $lastRunDate = Gdn_Format::toDateTime(Gdn::get('Plugin.DelayedNotifications.LastRunDate', 0));
        $email = new Gdn_Email();
        $period = Gdn::get('Plugin.DelayedNotifications.Period');
        $periodsArray = explode(',', Gdn::translate(Gdn::config('Plugins.DelayedNotifications.Periods')));
        $periodText = Gdn::translate($periodsArray[$period]);
        $email->subject(
            sprintf(
                Gdn::translate('[%1$s] %2$s'),
                Gdn::config('Garden.Title'),
                sprintf(
                    Gdn::translate('DelayedNotifications.EmailSubject'),
                    $lastRunDate,
                    $periodText
                )
            )
        );
        $email->to($user);
        if ($buttonAnchor) {
            $actionUrl .= "#Activity_" . $buttonAnchor;
        }
        $emailTemplate = $email->getEmailTemplate()
            ->setButton($actionUrl, Gdn::translate('Check out your notifications'))
            ->setTitle(
                wrap(
                    sprintf(
                        Gdn::translate('DelayedNotifications.EmbeddedTitle'),
                        $lastRunDate,
                        $periodText
                    ),
                    'span',
                    ['style' => 'font-size: 0.5em;']
                )
            )
            ->setMessage($messages, true);
        $this->EventArguments['Messages'] = $email;
        $this->EventArguments['Email'] = $email;
        $this->fireEvent('BeforeSendDelayedNotifications');

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
    public function getImage($body) {
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
        $imageUrl = $image;
        $i = stripos($imageUrl, "src=");
        if ($i === false) {
            return '';
        }
        $imageUrl = substr($imageUrl, $i+4);
        $delimiter = substr($imageUrl, 0, 1);
        if ($delimiter == '"' or $delimiter == "'") {
            $imageUrl = substr($imageUrl, 1);
            $i = stripos($imageUrl, $delimiter);
            if ($i>0) {
                $imageUrl = substr($imageUrl, 0, $i);
            }
        } else {
            return '';          //Can't trust local references in remote email system
        }
        $size = getimagesize($imageUrl);
        //Ignore smallimages (oftentimes "like"-like buttons)
        $minImageSize = Gdn::config('Plugins.DelayedNotifications.MinImageSize', "20");
        if ($size[0] < $minImageSize || $size[1] < $minImageSize) {
            return '';
        }
        return $imageUrl;
    }
}

if (!function_exists('touchConfig')) {
    /**
     * Make sure the config has a setting.
     *
     * This function is useful to call in the setup/structure of plugins to
     * make sure they have some default config set.
     *
     * @param string|array $name The name of the config key or an array of config key value pairs.
     * @param mixed $default The default value to set in the config.
     *
     * @deprecated 2.8 Use Gdn_Configuration::touch()
     */
    function touchConfig($name, $default = null) {
        deprecated(__FUNCTION__, 'Gdn_Configuration::touch()');
        Gdn::config()->touch($name, $default);
    }
}
