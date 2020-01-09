<?php

namespace RJPlugins;

use Gdn;
use Gdn_Plugin;
use Gdn_Format;
use ActivityModel;
use Gdn_Email;

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
            $period = $sender->Form->getFormValue('Period');
            if ($period) {
                Gdn::set('Plugin.NotificationConsolidation.Period', $period);
                $sender->informMessage(Gdn::translate('Saved'));
            }
        } else {
            $period = Gdn::get('Plugin.NotificationConsolidation.Period');
        }

        // Prepare content for the view.
        $sender->setData([
            'Title' => Gdn::translate('Notification Consolidation Settings'),
            'Description' => Gdn::translate('This plugin stops the immidate send out of notification mails. You have to define a period instead after which notifications are sent out in one consolidated mail.'),
            'SecretUrl' => $url,
            'UrlDescription' => Gdn::translate('You have to create a cron job that periodically polls this url:<br /><code>%s</code>'),
            'PeriodDescription' => Gdn::translate('Period (in hours) in which notification mails should be gathered before they are sent in one package'),
            'Period' => $period
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
        // This will cause an ActivityModel::SENT_SKIPPED status in Activity table.
        if ($args['User']['Attributes']['NotificationConsolidation'] ?? false == true) {
            Gdn::config()->saveToConfig('Garden.Email.Disabled', true, false);
        }
    }

    public function pluginController_notificationConsolidation_create($sender, $args) {
        $request = $sender->Request->get('secret');
        $secret = Gdn::get('Plugin.NotificationConsolidation.Secret');
        // Check if url has been called with the correct key.
        if ($request != $secret) {
            // throw permissionException();
decho('secret mismatch');
//            return;
        }

        // Check if enough time has passed since last run date.
        $period = Gdn::get('Plugin.NotificationConsolidation.Period', 24);
        $lastRunDate = Gdn::get('Plugin.NotificationConsolidation.LastRunDate', 0);
        if ($lastRunDate > Gdn_Format::toDateTime(time() - 3600 * $period)) {
decho('last run date too high');
            return;
        }

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
        foreach ($unsentActivities as $activity) {
            $user = $userModel->getID($activity['NotifyUserID']);
            // Do not proceed if the user has not opted in for a consolidation,
            // is banned or deleted.
            if (
                    $user->Banned == true ||
                    $user->Deleted == true ||
                    $user->Attributes['NotificationConsolidation'] ?? false == false
            ) {
                continue;
            }
            $notifications[$user->UserID][] = $activity;
        }
decho(dbdecode(dbencode($notifications)));
        // Concat activities to one message
        foreach ($notifications as $userID => $activities) {
decho(dbdecode(dbencode($activities)));
            $messages = [];
            foreach($activities as $activity) {
                $story = false;
                if ($activity['Story'] && $activity['Format']) {
                    $story = Gdn_Format::to(
                        $activity['Story'],
                        $activity['Format']
                    );
                }
decho(__LINE__);
                $messages[$activity['ActivityID']] = [
                    'DateInserted' => $activity['DateInserted'],
                    'Headline' => $this->getHeadline($activity),
                    'Story' => $story
                ];
decho($messages);
                $emailed = $this->sendMessage($userID, $messages);
                // delete if sent ok
                // update sent status?
            }
        }
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
        $lastRunDate = Gdn::get('Plugin.NotificationConsolidation.LastRunDate', 0);
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
        $message = '';
        foreach ($messages as $message) {
            $message .= '<p>'.$message['DateInserted'].'</p>';
            $message .= '<p>'.$message['Headline'].'</p>';
            if ($message['Story']) {
                $message .= '<p>'.$message['Story'].'</p>';
            }
            $message .= '<hr />';
        }
decho($message);
        $emailTemplate = $email->getEmailTemplate()
            ->setButton($actionUrl, Gdn::translate('Check it out'))
            ->setTitle(Gdn::translate('New Notifications'))
            ->setMessage($message, true);


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
