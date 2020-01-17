<?php

namespace RJPlugins;

use Gdn;
use Gdn_Plugin;
use Gdn_Format;
use ActivityModel;
use DiscussionModel;
use CommentModel;
use Gdn_Email;
use Vanilla\Invalid;
use \Datetime;

class NotificationConsolidationPlugin extends Gdn_Plugin {
    /**
     *  Run on startup to init sane config settings and db changes.
     *
     *  @return void.
     */
    public function setup() {
        Gdn::config()->touch(
            'Plugin.NotificationConsolidation.Periods',
            '2 hours,6 hours,12 hours,24 hours,2 days,3 days,4 days,5 days,6 days,1 week'
        );
        Gdn::config()->touch(
            'Plugin.NotificationConsolidation.MinImageSize',
            '20'
        );
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
            '/plugin/notificationconsolidation?secret='.$secret,
            true
        );
        $periodsarray = explode(',', Gdn::translate(Gdn::config('Plugin.NotificationConsolidation.Periods')));
        // Save period if the form has been posted.
        if ($sender->Form->authenticatedPostBack()) {
            // No need to validate Period as it is a dropdown.
            $period = $sender->Form->getFormValue('Period');
            // Validate Extract.
            $extract = $sender->Form->getFormValue('Extract');
            $getimage = $sender->Form->getFormValue('Getimage');
            $sender->Form->validateRule('Extract', 'ValidateRequired');
            $sender->Form->validateRule('Extract', 'ValidateInteger');
            // In case the browser doesn't support min/mx/step attributes we validate the range below
            if ($extract != 0 && ($extract < 30 || $extract > 300)) {
                $sender->Form->adderror(Gdn::translate('enter number between 30 and 300'), 'Extract');
            }
            // Save settings and give feedback.
            if ($sender->Form->errorCount() == 0) {
                Gdn::set('Plugin.NotificationConsolidation.Period', $period);
                Gdn::set('Plugin.NotificationConsolidation.Extract', $extract);
                Gdn::set('Plugin.NotificationConsolidation.Getimage', $getimage);
                if ($period == 0) {
                    Gdn::set('Plugin.NotificationConsolidation.LastRunDate', 0);    //Reset last run
                }
                $sender->informMessage(Gdn::translate('Saved'));
            }
        } else {
            $period = Gdn::get('Plugin.NotificationConsolidation.Period');
            $extract = Gdn::get('Plugin.NotificationConsolidation.Extract');
            $getimage = Gdn::get('Plugin.NotificationConsolidation.Getimage');
        }
        // Prepare content for the view.
        $sender->setData([
            'Title' => Gdn::translate('Notification Consolidation Settings'),
            'Description' => Gdn::translate('This plugin stops the immidate sending of notification emails. Instead, you specify a period after which notifications are sent in a single consolidated email.'),
            'SecretUrl' => anchor($url, $url, ['target'=> '_blank', 'title' => t('Click to open in a new window')]),
            'UrlDescription' => Gdn::translate('You have to create a cron job that periodically polls this url:<br /><code>%s</code>'),
            'PeriodDescription' => Gdn::translate('Number of hours to accummulate notification before emailing them as a bundle. Specify a number between 1 and 168 (a week).'),
            'ExtractDescription' => Gdn::translate('Request that a short content extract be included with the notification. Specify 0 for no extract or an integer between 30 and 300 for extract length.'),
            'GetimageDescription' => Gdn::translate('Indicate that attempt should be made to include small version of referred image in the email notifiction.'),
            'Periodsarray' => $periodsarray,
            'Period' => $period,
            'Extract' => $extract,
            'Getimage' => $getimage,
            'PeriodLabel' => Gdn::translate('Consolidation Period'),
            'ExtractLabel' => Gdn::translate('Include extract'),
            'GetimageLabel' => Gdn::translate('Include image')
        ]);

        $sender->render('settings', '', 'plugins/rj-notification-consolidation');
    }

    public function profileController_customNotificationPreferences_handler($sender) {
        // fugly: mixing view and model!
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
//decho('delaying');
            Gdn::config()->saveToConfig('Garden.Email.Disabled', true, false);
        }
    }

    public function pluginController_notificationConsolidation_create($sender, $args) {
        $request = $sender->Request->get('secret');
        $secret = Gdn::get('Plugin.NotificationConsolidation.Secret');
        // Check if url has been called with the correct key.
        if ($request != $secret) {
            throw permissionException(__CLASS__.' Invalid Parameters');
            return;
        }

        // Check if enough time has passed since last run date.
        $period = Gdn::get('Plugin.NotificationConsolidation.Period', '12 hours');
        if ($period == 0) {                 //Disabled 
            echo Gdn::translate('Plugin is disabled when period is set to zero');
            return;      
        }
        $lastRunDate = Gdn::get('Plugin.NotificationConsolidation.LastRunDate', 0);
        $nexttime = $this->nexttime($period, $lastRunDate);       //Next eligible email consolidation time
        if ($lastRunDate == 0) {            //If this was never set NOW is as good as any time...
            $nexttime = time();
            Gdn::set('Plugin.NotificationConsolidation.LastRunDate', time());
        } elseif ($nexttime >  time()) {       //Still have more time based on current period
            if (isset($_GET['force'])) {       //But still do it if "force" specified (good for testing)
                $periodsarray = explode(',', Gdn::config('Plugin.NotificationConsolidation.Periods'));
                $goback = end($periodsarray);
                $lastRunDate = strtotime('- '. $goback); //Simulate "it's time to run"
            } else {
//decho('not yet...last run:'.Gdn_Format::toDateTime($lastRunDate).' nexttime:'.Gdn_Format::toDateTime($nexttime));
                return;
            }
        }
        if ($lastRunDate > $nexttime) {             //Should never happen
//decho('last run date too high. Period:'.$periodtime);
            Gdn::set('Plugin.NotificationConsolidation.LastRunDate', time());   //Fix by resetting last time
            return;                                                             //but wait for next cron
        }
//decho('OK.now:'.Gdn_Format::toDateTime(time()).' nexttime:'.Gdn_Format::toDateTime($nexttime));
        // Get _all_ open activities.
        $model = new ActivityModel();
        $unsentActivities = $model->getWhere(
            [
                'Emailed' => $model::SENT_SKIPPED,
                'DateInserted > ' => Gdn_Format::toDateTime($lastRunDate)
            ],
            'NotifyUserID, DateInserted'
        );
//decho(dbdecode(dbencode($unsentActivities)));
        if (!count($unsentActivities)) {                //No unsents?
            return;                                     //We're all done here
        }
        // Group them by user.
        $notifications = [];
        $userModel = Gdn::userModel();
        $extract = Gdn::get('Plugin.NotificationConsolidation.Extract', false);
        $getimage = Gdn::get('Plugin.NotificationConsolidation.Getimage', true);
//decho('checking unsents');
        foreach ($unsentActivities as $activity) {
//decho("Processing activity id".$activity['ActivityID'] . 'for user '.$activity['NotifyUserID']);

            if (!isset($buttoanchor[$activity['NotifyUserID']])) {
                $buttoanchor[$activity['NotifyUserID']] = $activity['ActivityID'];
            }
//decho ($buttoanchor);
            $user = $userModel->getID($activity['NotifyUserID']);
            // Do not proceed if the user has not opted in for a consolidation,
            // is banned or deleted or hasn't logged on for two years.
            if (
                    $user->Banned == true ||
                    $user->Deleted == true ||
                    $user->DateLastActive < Gdn_Format::toDateTime(strtotime('-2 years')) ||
                    $user->Attributes['NotificationConsolidation'] == false
            ) {
/*decho('skipping. user='.$user->UserID . ' name='.$user->Name  . ' DateLastActive='.$user->DateLastActive . 
       ' -2years='.Gdn_Format::toDateTime(strtotime('-2 years')) . 
       ' attributes:'.$user->Attributes['NotificationConsolidation']);/**/
                continue;
            }
            $notifications[$user->UserID][] = $activity;
        }
//decho(count($notifications));
        if (!count($notifications)) {                   //No users to notify?
            return;                                     //We're all done here
        }
//decho(dbdecode(dbencode($notifications)));
        // Foreach user concatenate activities notifications to one message
        $messageStream = '';                  //Combined message stream
        echo '<br>' . sprintf(
                              Gdn::translate('Processing %1$s users'),
                              count($notifications)
                              );
        foreach ($notifications as $userID => $activities) {
//decho("Processing userid".$userID );
//decho(dbdecode(dbencode($activities)));
            foreach($activities as $activity) {
//decho("Processing userid".$userID . ' activity id:'.$activity['ActivityID']. $activity['RecordType']. ':'.$activity['RecordID']);
                $story = false;
                $skip = false;  //Few reasons to skip: discussion/comment deleted, originator is the one to be notified...
                $message = '';
                if ($activity['ActivityUserID'] == $activity['NotifyUserID']) {
                    $message .= 'DEBUG:ActivityUserID = NotifyUserID';
                }
                if ($activity['NotifyUserID'] == $activity['InsertUserID']) {
                    $message .= 'DEBUG:NotifyUserID = InsertUserID';
                }
                $object = $this->getobject($activity);
                if ($object == false) {
                    $skip = true;                           //Presume object is deleted
                } elseif ($object == -1) {                  //Special handling for other notifications
                } else {                                    //Handling of discussion/comment notifications
                    if ($getimage) {
                        $story .=   $this->getimage($object->Body);
                    }
                    if ($extract) {
                        $story .= sliceString(strip_tags($object->Body, '<p><i><b><br>'), $extract);
                    }
                }
                if (!$skip) {
                    $message .= $this->formatmessage(
                                                    $activity['DateInserted'],
                                                    $activity['Photo'],
                                                    val('Prefix', $object, ''),
                                                    $this->getHeadline($activity),
                                                    $story
                                                    );
                    $messageStream .= wrap($message,'div'); //accummulate message stream that goes in one email 
                }                
            }
            //  Send the accummulated messages
            if ($this->sendMessage($userID, $messageStream, $buttoanchor[$userID]) == ActivityModel::SENT_OK) {  //successful send?
                foreach($activities as $activity) {                                 //Mark all related activities as emailed
//decho (__LINE__.' for testing pretend clearing acivity as sent && updting lastrundte');
                    //TEST//$model->setProperty($activity['ActivityID'], 'Emailed', ActivityModel::SENT_OK);
                }
                Gdn::set('Plugin.NotificationConsolidation.LastRunDate', time());   //Update last run date
            }
            $messageStream = '';
        }
    }
    /**
     * Format individual message.
     *
     * @param string $date       notification related date.
     * @param int    $photo      originator photo.
     * @param int    $prefix     Discussion Prefix (if set).
     * @param string $headline   notification headline.
     * @param string $story      additional optional text
     * @param string $format     story format
     *
     * @return string formatted notification.
     */
    private function formatmessage($date, $photo, $prefix, $headline, $story, $format = 'HTML') {
        // Not counting on css for the resulting email system
        if ($photo) {
            $message = wrap(
                            '<img src="' . $photo . '" style="width:22px;height:22px;" </img>',
                            'span',
                            ['style' => 'display:inline-block;margin:4px;vertical-align: middle;']
                            );
        }
        $message .= wrap(
                         $headline . ' ' . $date . ' ',
                         'span',
                         ['style'=> 'vertical-align: middle;']
                         );
        if ($prefix) {
            $message .= wrap(
                            $prefix,
                            'span',
                            ['style' => 'background:darkcyan;color:white;']
                            );
        }
        if ($story) {
            $story = '<br>' . wrap(
                                    Gdn_Format::to(
                                                    $story,
                                                    $format),
                                    'span',
                                    ['style' => 'padding-left:10%;display:block;white-space:break-spaces;']
                                );
            $message .= wrap($story,'span', ['style' => 'font-style: italic;color:#0074d9;']);
        }
        return wrap(
                    $message,
                    'div',
                    ['style' => 'border:1px solid #0074d9;display:block;width:90%;white-space: break-spaces;']
                    );
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
     * Calclulate next eligible email notification time based on passed period index nd last run.
     *
     * @param int  $period  index of period.
     * @param time $lastrun time of last run.
     *
     * @return int time of next eligible run time
     */
    private function nexttime($period, $lastrun) {
        if (!$period) return false;             //zero means disabled
        $periodsarray = explode(',', Gdn::config('Plugin.NotificationConsolidation.Periods'));
        // array must be strtotime eligible...
        //  e.g. 2 hours,6 hours,12 hours,24 hours,2 days,3 days,4 days,5 days,6 days,1 week
        $datetime = new DateTime();
        $datetime->setTimestamp($lastrun);
        $datetime->modify('+' . $periodsarray[$period]);     //Next eligible time
//decho ($datetime);
//decho ($datetime->format('Y-m-d H:i:s'));
        $nexttime = strtotime($datetime->format('Y-m-d H:i:s'));

//decho(' nexttime='. Gdn_Format::toDateTime($nexttime). ' lastrun='.Gdn_Format::toDateTime($lastrun));
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
     * @param int    $recipient   UserID ID of the user.
     * @param array  $messages    The messages to be sent.
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
//decho($messages);
        if ($buttoanchor) {
            $actionUrl .= "#Activity_" . $buttoanchor;
        }
        $emailTemplate = $email->getEmailTemplate()
            ->setButton($actionUrl, Gdn::translate('Check out your notifications'))
            ->setTitle(
                sprintf(
                    Gdn::translate('NotificationConsolidation.EmbeddedTitle'),
                    $lastRunDate
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
        //decho ($imageurl);
        $delimiter = substr($imageurl,0,1);
        decho ($delimiter);
        if ($delimiter == '"' OR $delimiter == "'") {
            $imageurl = substr($imageurl,1);
            $i = stripos($imageurl, $delimiter);
            if ($i>0) $imageurl = substr($imageurl,0,$i);
        } else {
            return '';          //Can't trust local references in remote email system
        }
//decho ($imageurl);
        $size = getimagesize($imageurl);
        //echo "<br>".__LINE__." size:".var_dump($size);
        //decho ($size);            //Ignoresmallimages (oftentimes "like"-like buttons)
        $minImageSize = Gdn::config('Plugin.NotificationConsolidation.MinImageSize', 20);
        if ($size[0] < $minImageSize || $size[1] < $minImageSize) {
            //decho ($size);
            return '';
        }

        $image = '<div style="border:2px solid blue;border-radius:4px;margin:4px;">'.
                    '<img width="100px"  ' .
                    'style="border-radius:4px;margin:4px;" src="' .
                    $imageurl . '" >'.
                  '</div>';
//decho(htmlentities($image));
        return $image;
    }
}
