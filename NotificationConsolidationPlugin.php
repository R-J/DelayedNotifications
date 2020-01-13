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

class NotificationConsolidationPlugin extends Gdn_Plugin {
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

        // Save period if the form has been posted.
        if ($sender->Form->authenticatedPostBack()) {
            // Validate Period.
            $period = $sender->Form->getFormValue('Period');
            $sender->Form->validateRule('Period', 'ValidateRequired');
            $sender->Form->validateRule('Period', 'ValidateInteger');
            $sender->Form->validateRule(
                'Period',
                function ($period) {
                    if ($period >= 0 && $period <= 7*24) {
                        return true;
                    }
                    return new Invalid('');
                },
                Gdn::translate('Period must be numeric between 0 and 168 (one week)')
            );
            // Validate Extract.
            $extract = $sender->Form->getFormValue('Extract');
            $sender->Form->validateRule('Extract', 'ValidateRequired');
            $sender->Form->validateRule('Extract', 'ValidateInteger');
            $sender->Form->validateRule(
                'Extract',
                function ($extract) {
                    if ($extract == 0 || ($extract >= 30 && $extract <= 30)) {
                        return true;
                    }
                    return new Invalid('');
                },
                Gdn::translate('Extract must be 0 or an integer between 30 and 300')
            );
            // Save settings and give feedback.
            if ($sender->Form->errorCount() == 0) {
                Gdn::set('Plugin.NotificationConsolidation.Period', $period);
                Gdn::set('Plugin.NotificationConsolidation.Extract', $extract);
                $sender->informMessage(Gdn::translate('Saved'));
            }
        } else {
            $period = Gdn::get('Plugin.NotificationConsolidation.Period');
            $extract = Gdn::get('Plugin.NotificationConsolidation.Extract');
        }

        // Prepare content for the view.
        $sender->setData([
            'Title' => Gdn::translate('Notification Consolidation Settings'),
            'Description' => Gdn::translate('This plugin stops the immidate sending of notification emails. Instead, you specify a period after which notifications are sent in a single consolidated email.'),
            'SecretUrl' => $url,
            'UrlDescription' => Gdn::translate('You have to create a cron job that periodically polls this url:<br /><code>%s</code>'),
            'PeriodDescription' => Gdn::translate('Number of hours to accummulate notification before emailing them as a bundle. Specify a number between 1 and 168 (a week).'),
            'ExtractDescription' => Gdn::translate('Request that a short content extract be included with the notification. Specify 0 for no extract or an integer between 30 and 300 for extract length.'),
            'Period' => $period,
            'Extract' => $extract
        ]);

        $sender->render('settings', '', 'plugins/rj-notification-consolidation');
    }

    public function profileController_customNotificationPreferences_handler($sender) {
        // fugly: mixing view and model!
        $attributes = [];
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
            Gdn::translate('Check this box to receive all notification mails consolidated only once a day'),
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
            throw permissionException(__CLASS__." Invalid Parameters");
            return;
        }

        // Check if enough time has passed since last run date.
        $period = Gdn::get('Plugin.NotificationConsolidation.Period', 24);
        if ($period == 0) {
            return;
        }
        $lastRunDate = Gdn::get('Plugin.NotificationConsolidation.LastRunDate', 0);
        if ($lastRunDate == 0) {            //If this was never set NOW is as good as any time...
            $lastRunDate = Gdn_Format::toDateTime(time() - 3600 * $period);
            Gdn::set('Plugin.NotificationConsolidation.LastRunDate', $lastRunDate);
        }
        if ($lastRunDate > Gdn_Format::toDateTime(time() - 3600 * $period)) {   //RB: ?Why, What scenario?
decho('last run date too high. Period:'.$period);
            return;
        }
//decho('last run date:'.Gdn_Format::toDateTime($lastRunDate));
        // Get _all_ open activities.
        $model = new ActivityModel();
        $unsentActivities = $model->getWhere(
            [
                'Emailed' => $model::SENT_SKIPPED,
                'DateInserted > ' => $lastRunDate
            ],
            'NotifyUserID, DateInserted'
        );
        // Group them by user.
        $notifications = [];
        $userModel = Gdn::userModel();
        $extract = Gdn::get('Plugin.NotificationConsolidation.Extract', false);
//decho('checking unsents');
        foreach ($unsentActivities as $activity) {
            $user = $userModel->getID($activity['NotifyUserID']);;
            // Do not proceed if the user has not opted in for a consolidation,
            // is banned or deleted or hasn't logged on for two years.
            if (
                    $user->Banned == true ||
                    $user->Deleted == true ||
                    $user->DateLastActive < Gdn_Format::toDateTime(strtotime("-2 years")) ||
                    $user->Attributes['NotificationConsolidation'] == false
            ) {
decho('skipping user '.$user->UserID . ' attributes:'.$user->Attributes['NotificationConsolidation']);
                continue;
            }
            $notifications[$user->UserID][] = $activity;
//decho(' ');
        }
//decho(dbdecode(dbencode($notifications)));
        // Foreach user concatename activities notifications to one message
        $mstream = '';                  //Combined message stream
        foreach ($notifications as $userID => $activities) {
//decho(dbdecode(dbencode($activities)));
            foreach($activities as $activity) {
                $story = false;
                $skip = false;  //Few reasons to skip: discussion/comment deleted, originator is the one to be notified...
                if ($activity["ActivityUserID"] == $activity["NotifyUserID"]) {
                    $message .= "DEBUG:ActivityUserID = NotifyUserID";
                }
                if ($activity["NotifyUserID"] == $activity["InsertUserID"]) {
                    $message .= "DEBUG:NotifyUserID = InsertUserID";
                }
                $object = $this->getobject($activity);
                if ($object == false) {
                    $skip = true;                           //Presume object is deleted
                } elseif ($object == -1) {                  //Special handling for other notifications
                } else {                                    //Handling of discussion/comment notifications
                    if ($extract) {
                        $story .= sliceString(strip_tags($object->Body), $extract);
                    }
                }
                if (!$skip) {
                    $message .= $this->formatmessage(
                                                    $activity['DateInserted'],
                                                    $activity['Photo'],
                                                    val("Prefix", $object, ''),
                                                    $this->getHeadline($activity),
                                                    $story
                                                    );
                    $mstream .= wrap($message,'p');   //accummulate message stream that goes in one email 
                }                
            }
            //  Send the accummulated messages
            if ($this->sendMessage($userID, $mstream) == ActivityModel::SENT_OK) {  //successful send?
                $mstream = '';
                foreach($activities as $activity) {                                 //Mark all related activities as emailed
//decho (__LINE__." for testing pretend clearing acivity as sent && updting lastrundte");
                    $model->setProperty($activity['ActivityID'], 'Emailed', ActivityModel::SENT_OK);
                }
                Gdn::set('Plugin.NotificationConsolidation.LastRunDate', time());   //Update last run date
            }
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
    private function formatmessage($date, $photo, $prefix, $headline, $story, $format = "HTML") {
        // Not counting on css for the resulting email system
        if ($photo) {
            $message = wrap(
                            '<img src="' . $photo . '" style="width:22px;height:22px;" </img>',
                            'span',
                            ['style' => 'display:inline-block;margin:4px;vertical-align: middle;']
                            );
        }
        $message .= wrap(
                         $headline . " " . $date . ' ',
                         'span',
                         ['style'=> 'vertical-align: middle;']
                         );
        if ($prefix) {
            $message .= wrap(
                            $prefix,
                            'span',
                            ['style' => "background:darkcyan;color:white;"]
                            );
        }
        if ($story) {
            $story = "<br>" . wrap(
                                    Gdn_Format::to(
                                                    $story,
                                                    $format),
                                    'span',
                                    ['style' => 'padding-left:10%;display:block;white-space:break-spaces;']
                                );
            $message .= wrap($story,'span', ["style" => "font-style: italic;color:#0074d9;"]);
        }
        return wrap(
                    $message,
                    'span',
                    ["style" => "border:1px solid #0074d9;display:block;width:90%;white-space: break-spaces;"]
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
        if ($activity["RecordType"] == "Discussion") {
            $recordmodel = new DiscussionModel();
        } elseif ($activity["RecordType"] == "Comment") {
            $recordmodel = new CommentModel();
        } else {
            return -1;
        }
        $object = $recordmodel->GetID($activity["RecordID"]);
        if ($object) {
            return $object;
        }
        return false;
    }

    private function getRecordType($activity) {
        // Only handle Discussions and Comments.
        if (!in_array($activity['RecordType'], ['Discussion', 'Comment'])) {
            return null;
        }
        // Create model dynamically.
        $modelName = $activity['RecordType'].'Model';
        $record = (new $modelName())->getID($activity['RecordID']);
        if ($record) {
            return $record;
        }

        return false;
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
     * @param int $recipientUserID ID of the user.
     * @param array $messages The messages to be sent.
     *
     * @return int One of ActivityModel SENT status.
     */
    private function sendMessage($recipientUserID, $messages) {
        // Prepare mail
        $actionUrl = Gdn::request()->url('/profile/notifications', true);
        $user = Gdn::userModel()->getID($recipientUserID);
        $lastRunDate = Gdn_Format::toDateTime(Gdn::get('Plugin.NotificationConsolidation.LastRunDate', 0));
        $email = new Gdn_Email();
        $email->subject(
            sprintf(
                Gdn::translate('[%1$s] %2$s'),
                Gdn::config('Garden.Title'),
                sprintf(
                    Gdn::translate('Consolidated Notifications since %1$s'),
                    $lastRunDate
                )
            )
        );
        $email->to($user);
//decho($messages);
        /*foreach ($messages as $message) {
var_dump($message);
            $message  = '<p>'.$message['DateInserted'].'</p>';
            $message .= '<p>'.$message['Headline'].'</p>';
            if ($message['Story']) {
                $message .= '<p>'.$message['Story'].'</p>';
            }
            $message .= '<br />';
        }*/
        $emailTemplate = $email->getEmailTemplate()
            ->setButton($actionUrl, Gdn::translate('Check it out'))
            ->setTitle(Gdn::translate('New Notifications'))
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
}
