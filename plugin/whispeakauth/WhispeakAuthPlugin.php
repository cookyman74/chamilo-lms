<?php
/* For licensing terms, see /license.txt */

use Chamilo\CoreBundle\Entity\ExtraField;
use Chamilo\CoreBundle\Entity\ExtraFieldValues;
use Chamilo\PluginBundle\Entity\WhispeakAuth\LogEvent;
use Chamilo\PluginBundle\Entity\WhispeakAuth\LogEventLp;
use Chamilo\PluginBundle\Entity\WhispeakAuth\LogEventQuiz;
use Chamilo\UserBundle\Entity\User;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class WhispeakAuthPlugin.
 */
class WhispeakAuthPlugin extends Plugin implements HookPluginInterface
{
    const SETTING_ENABLE = 'enable';
    const SETTING_MAX_ATTEMPTS = 'max_attempts';
    const SETTING_2FA = '2fa';

    const EXTRAFIELD_AUTH_UID = 'whispeak_auth_uid';
    const EXTRAFIELD_LP_ITEM = 'whispeak_lp_item';
    const EXTRAFIELD_QUIZ_QUESTION = 'whispeak_quiz_question';

    const API_URL = 'http://api.whispeak.io:8080/v1.1/';

    const SESSION_FAILED_LOGINS = 'whispeak_failed_logins';
    const SESSION_2FA_USER = 'whispeak_user_id';
    const SESSION_LP_ITEM = 'whispeak_lp_item';
    const SESSION_QUIZ_QUESTION = 'whispeak_quiz_question';
    const SESSION_AUTH_PASSWORD = 'whispeak_auth_password';
    const SESSION_SENTENCE_TEXT = 'whispeak_sentence_text';

    /**
     * StudentFollowUpPlugin constructor.
     */
    protected function __construct()
    {
        parent::__construct(
            '0.1',
            'Angel Fernando Quiroz',
            [
                self::SETTING_ENABLE => 'boolean',
                self::SETTING_MAX_ATTEMPTS => 'text',
                self::SETTING_2FA => 'boolean',
            ]
        );
    }

    /**
     * Get the admin URL for the plugin if Plugin::isAdminPlugin is true.
     *
     * @return string
     */
    public function getAdminUrl()
    {
        $webPath = api_get_path(WEB_PLUGIN_PATH).$this->get_name();

        return "$webPath/admin.php";
    }

    /**
     * @return WhispeakAuthPlugin
     */
    public static function create()
    {
        static $result = null;

        return $result ? $result : $result = new self();
    }

    public function install()
    {
        $this->installExtraFields();
        $this->installEntities();
        $this->installHook();
    }

    public function uninstall()
    {
        $this->uninstallHook();
        $this->uninstallExtraFields();
        $this->uninstallEntities();
    }

    /**
     * @return string
     */
    public function getEntityPath()
    {
        return api_get_path(SYS_PATH).'src/Chamilo/PluginBundle/Entity/'.$this->getCamelCaseName();
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        $token = file_get_contents(__DIR__.'/tokenTest');

        return trim($token);
    }

    /**
     * @return ExtraField
     */
    public static function getAuthUidExtraField()
    {
        $em = Database::getManager();
        $efRepo = $em->getRepository('ChamiloCoreBundle:ExtraField');

        /** @var ExtraField $extraField */
        $extraField = $efRepo->findOneBy(
            [
                'variable' => self::EXTRAFIELD_AUTH_UID,
                'extraFieldType' => ExtraField::USER_FIELD_TYPE,
            ]
        );

        return $extraField;
    }

    /**
     * @return ExtraField
     */
    public static function getLpItemExtraField()
    {
        $efRepo = Database::getManager()->getRepository('ChamiloCoreBundle:ExtraField');

        /** @var ExtraField $extraField */
        $extraField = $efRepo->findOneBy(
            [
                'variable' => self::EXTRAFIELD_LP_ITEM,
                'extraFieldType' => ExtraField::LP_ITEM_FIELD_TYPE,
            ]
        );

        return $extraField;
    }

    /**
     * @return ExtraField
     */
    public static function getQuizQuestionExtraField()
    {
        $efRepo = Database::getManager()->getRepository('ChamiloCoreBundle:ExtraField');

        /** @var ExtraField $extraField */
        $extraField = $efRepo->findOneBy(
            [
                'variable' => self::EXTRAFIELD_QUIZ_QUESTION,
                'extraFieldType' => ExtraField::QUESTION_FIELD_TYPE,
            ]
        );

        return $extraField;
    }

    /**
     * @param int $userId
     *
     * @return ExtraFieldValues
     */
    public static function getAuthUidValue($userId)
    {
        $extraField = self::getAuthUidExtraField();
        $em = Database::getManager();
        $efvRepo = $em->getRepository('ChamiloCoreBundle:ExtraFieldValues');

        /** @var ExtraFieldValues $value */
        $value = $efvRepo->findOneBy(['field' => $extraField, 'itemId' => $userId]);

        return $value;
    }

    /**
     * Get the whispeak_lp_item value for a LP item ID.
     *
     * @param int $lpItemId
     *
     * @return array|false
     */
    public static function getLpItemValue($lpItemId)
    {
        $efv = new ExtraFieldValue('lp_item');
        $value = $efv->get_values_by_handler_and_field_variable($lpItemId, self::EXTRAFIELD_LP_ITEM);

        return $value;
    }

    /**
     * @param int $lpItemId
     *
     * @return bool
     */
    public static function isLpItemMarked($lpItemId)
    {
        if (!self::create()->isEnabled()) {
            return false;
        }

        $value = self::getLpItemValue($lpItemId);

        return !empty($value) && !empty($value['value']);
    }

    /**
     * Get the whispeak_quiz_question value for a quiz question ID.
     *
     * @param int $questionId
     *
     * @return array|false
     */
    public static function getQuizQuestionValue($questionId)
    {
        $efv = new ExtraFieldValue('question');
        $value = $efv->get_values_by_handler_and_field_variable($questionId, self::EXTRAFIELD_QUIZ_QUESTION);

        return $value;
    }

    /**
     * @param int $questionId
     *
     * @return bool
     */
    public static function isQuizQuestionMarked($questionId)
    {
        if (!self::create()->isEnabled()) {
            return false;
        }

        $value = self::getQuizQuestionValue($questionId);

        return !empty($value) && !empty($value['value']);
    }

    /**
     * @param int $questionId
     *
     * @return bool
     */
    public static function questionRequireAuthentify($questionId)
    {
        $isMarked = self::isQuizQuestionMarked($questionId);

        if (!$isMarked) {
            return false;
        }

        $questionInfo = ChamiloSession::read(self::SESSION_QUIZ_QUESTION, []);

        if (empty($questionInfo)) {
            return true;
        }

        if ((int) $questionId !== $questionInfo['question']) {
            return true;
        }

        if (false === $questionInfo['passed']) {
            return true;
        }

        return false;
    }

    /**
     * @param int $userId
     *
     * @return bool
     */
    public static function checkUserIsEnrolled($userId)
    {
        $value = self::getAuthUidValue($userId);

        if (empty($value)) {
            return false;
        }

        return !empty($value->getValue());
    }

    /**
     * @return string
     */
    public static function getEnrollmentUrl()
    {
        return api_get_path(WEB_PLUGIN_PATH).'whispeakauth/enrollment.php';
    }

    /**
     * @param User   $user
     * @param string $uid
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function saveEnrollment(User $user, $uid)
    {
        $em = Database::getManager();
        $extraFieldValue = self::getAuthUidValue($user->getId());

        if (empty($extraFieldValue)) {
            $extraField = self::getAuthUidExtraField();
            $now = new DateTime('now', new DateTimeZone('UTC'));

            $extraFieldValue = new ExtraFieldValues();
            $extraFieldValue
                ->setField($extraField)
                ->setItemId($user->getId())
                ->setUpdatedAt($now);
        }

        $extraFieldValue->setValue($uid);

        $em->persist($extraFieldValue);
        $em->flush();
    }

    /**
     * @return bool
     */
    public function toolIsEnabled()
    {
        return 'true' === $this->get(self::SETTING_ENABLE);
    }

    /**
     * Access not allowed when tool is not enabled.
     *
     * @param bool $printHeaders Optional. Print headers.
     */
    public function protectTool($printHeaders = true)
    {
        if ($this->toolIsEnabled()) {
            return;
        }

        api_not_allowed($printHeaders);
    }

    /**
     * Convert the language name to ISO-639-2 code (3 characters).
     *
     * @param string $languageName
     *
     * @return string
     */
    public static function getLanguageIsoCode($languageName)
    {
        $listIso3 = [
            'ab' => 'abk',
            'aa' => 'aar',
            'af' => 'afr',
            'ak' => 'aka',
            'sq' => 'sqi',
            'am' => 'amh',
            'ar' => 'ara',
            'an' => 'arg',
            'hy' => 'hye',
            'as' => 'asm',
            'av' => 'ava',
            'ae' => 'ave',
            'ay' => 'aym',
            'az' => 'aze',
            'bm' => 'bam',
            'ba' => 'bak',
            'eu' => 'eus',
            'be' => 'bel',
            'bn' => 'ben',
            'bh' => 'bih',
            'bi' => 'bis',
            'bs' => 'bos',
            'br' => 'bre',
            'bg' => 'bul',
            'my' => 'mya',
            'ca' => 'cat',
            'ch' => 'cha',
            'ce' => 'che',
            'ny' => 'nya',
            'zh' => 'zho',
            'cv' => 'chv',
            'kw' => 'cor',
            'co' => 'cos',
            'cr' => 'cre',
            'hr' => 'hrv',
            'cs' => 'ces',
            'da' => 'dan',
            'dv' => 'div',
            'nl' => 'nld',
            'dz' => 'dzo',
            'en' => 'eng',
            'eo' => 'epo',
            'et' => 'est',
            'ee' => 'ewe',
            'fo' => 'fao',
            'fj' => 'fij',
            'fi' => 'fin',
            'fr' => 'fra',
            'ff' => 'ful',
            'gl' => 'glg',
            'ka' => 'kat',
            'de' => 'deu',
            'el' => 'ell',
            'gn' => 'grn',
            'gu' => 'guj',
            'ht' => 'hat',
            'ha' => 'hau',
            'he' => 'heb',
            'hz' => 'her',
            'hi' => 'hin',
            'ho' => 'hmo',
            'hu' => 'hun',
            'ia' => 'ina',
            'id' => 'ind',
            'ie' => 'ile',
            'ga' => 'gle',
            'ig' => 'ibo',
            'ik' => 'ipk',
            'io' => 'ido',
            'is' => 'isl',
            'it' => 'ita',
            'iu' => 'iku',
            'ja' => 'jpn',
            'jv' => 'jav',
            'kl' => 'kal',
            'kn' => 'kan',
            'kr' => 'kau',
            'ks' => 'kas',
            'kk' => 'kaz',
            'km' => 'khm',
            'ki' => 'kik',
            'rw' => 'kin',
            'ky' => 'kir',
            'kv' => 'kom',
            'kg' => 'kon',
            'ko' => 'kor',
            'ku' => 'kur',
            'kj' => 'kua',
            'la' => 'lat',
            'lb' => 'ltz',
            'lg' => 'lug',
            'li' => 'lim',
            'ln' => 'lin',
            'lo' => 'lao',
            'lt' => 'lit',
            'lu' => 'lub',
            'lv' => 'lav',
            'gv' => 'glv',
            'mk' => 'mkd',
            'mg' => 'mlg',
            'ms' => 'msa',
            'ml' => 'mal',
            'mt' => 'mlt',
            'mi' => 'mri',
            'mr' => 'mar',
            'mh' => 'mah',
            'mn' => 'mon',
            'na' => 'nau',
            'nv' => 'nav',
            'nd' => 'nde',
            'ne' => 'nep',
            'ng' => 'ndo',
            'nb' => 'nob',
            'nn' => 'nno',
            'no' => 'nor',
            'ii' => 'iii',
            'nr' => 'nbl',
            'oc' => 'oci',
            'oj' => 'oji',
            'cu' => 'chu',
            'om' => 'orm',
            'or' => 'ori',
            'os' => 'oss',
            'pa' => 'pan',
            'pi' => 'pli',
            'fa' => 'fas',
            'pl' => 'pol',
            'ps' => 'pus',
            'pt' => 'por',
            'qu' => 'que',
            'rm' => 'roh',
            'rn' => 'run',
            'ro' => 'ron',
            'ru' => 'rus',
            'sa' => 'san',
            'sc' => 'srd',
            'sd' => 'snd',
            'se' => 'sme',
            'sm' => 'smo',
            'sg' => 'sag',
            'sr' => 'srp',
            'gd' => 'gla',
            'sn' => 'sna',
            'si' => 'sin',
            'sk' => 'slk',
            'sl' => 'slv',
            'so' => 'som',
            'st' => 'sot',
            'es' => 'spa',
            'su' => 'sun',
            'sw' => 'swa',
            'ss' => 'ssw',
            'sv' => 'swe',
            'ta' => 'tam',
            'te' => 'tel',
            'tg' => 'tgk',
            'th' => 'tha',
            'ti' => 'tir',
            'bo' => 'bod',
            'tk' => 'tuk',
            'tl' => 'tgl',
            'tn' => 'tsn',
            'to' => 'ton',
            'tr' => 'tur',
            'ts' => 'tso',
            'tt' => 'tat',
            'tw' => 'twi',
            'ty' => 'tah',
            'ug' => 'uig',
            'uk' => 'ukr',
            'ur' => 'urd',
            'uz' => 'uzb',
            've' => 'ven',
            'vi' => 'vie',
            'vo' => 'vol',
            'wa' => 'wln',
            'cy' => 'cym',
            'wo' => 'wol',
            'fy' => 'fry',
            'xh' => 'xho',
            'yi' => 'yid',
            'yo' => 'yor',
            'za' => 'zha',
            'zu' => 'zul',
        ];

        $iso2 = api_get_language_isocode($languageName);
        $iso3 = isset($listIso3[$iso2]) ? $listIso3[$iso2] : $listIso3['en'];

        return $iso3;
    }

    /**
     * Get the max_attemtps option.
     *
     * @return int
     */
    public function getMaxAttempts()
    {
        return (int) $this->get(self::SETTING_MAX_ATTEMPTS);
    }

    /**
     * Install hook when saving the plugin configuration.
     *
     * @return WhispeakAuthPlugin
     */
    public function performActionsAfterConfigure()
    {
        $observer = WhispeakConditionalLoginHook::create();

        if ('true' === $this->get(self::SETTING_2FA)) {
            HookConditionalLogin::create()->attach($observer);
        } else {
            HookConditionalLogin::create()->detach($observer);
        }

        return $this;
    }

    /**
     * This method will call the Hook management insertHook to add Hook observer from this plugin.
     */
    public function installHook()
    {
        $observer = WhispeakMyStudentsLpTrackingHook::create();
        HookMyStudentsLpTracking::create()->attach($observer);

        $observer = WhispeakMyStudentsQuizTrackingHook::create();
        HookMyStudentsQuizTracking::create()->attach($observer);
    }

    /**
     * This method will call the Hook management deleteHook to disable Hook observer from this plugin.
     */
    public function uninstallHook()
    {
        $observer = WhispeakConditionalLoginHook::create();
        HookConditionalLogin::create()->detach($observer);

        $observer = WhispeakMyStudentsLpTrackingHook::create();
        HookMyStudentsLpTracking::create()->detach($observer);
    }

    /**
     * @param int $userId
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     *
     * @return bool
     */
    public static function deleteEnrollment($userId)
    {
        $extraFieldValue = self::getAuthUidValue($userId);

        if (empty($extraFieldValue)) {
            return false;
        }

        $em = Database::getManager();
        $em->remove($extraFieldValue);
        $em->flush();

        return true;
    }

    /**
     * Check if the WhispeakAuth plugin is installed and enabled.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return parent::isEnabled() && 'true' === api_get_plugin_setting('whispeakauth', self::SETTING_ENABLE);
    }

    /**
     * @param int $lpItemId
     *
     * @return bool
     */
    public static function isAllowedToSaveLpItem($lpItemId)
    {
        if (!self::isLpItemMarked($lpItemId)) {
            return true;
        }

        $markedItem = ChamiloSession::read(self::SESSION_LP_ITEM, []);

        if (empty($markedItem)) {
            return true;
        }

        if ((int) $lpItemId !== (int) $markedItem['lp_item']) {
            return true;
        }

        return false;
    }

    /**
     * Display a error message.
     *
     * @param string|null $error Optional. The message text
     */
    public static function displayNotAllowedMessage($error = null)
    {
        $error = empty($error) ? get_lang('NotAllowed') : $error;

        echo Display::return_message($error, 'error', false);

        exit;
    }

    /**
     * @param int      $questionId
     * @param Exercise $exercise
     *
     * @throws Exception
     *
     * @return string
     */
    public static function quizQuestionAuthentify($questionId, Exercise $exercise)
    {
        ChamiloSession::write(
            self::SESSION_QUIZ_QUESTION,
            [
                'quiz' => (int) $exercise->iId,
                'question' => (int) $questionId,
                'url_params' => $_SERVER['QUERY_STRING'],
                'passed' => false,
            ]
        );

        $template = new Template('', false, false, false, true, false, false);
        $template->assign('question', $questionId);
        $template->assign('exercise', $exercise->iId);
        $content = $template->fetch('whispeakauth/view/quiz_question.html.twig');

        echo $content;
    }

    /**
     * @param int $userId
     * @param int $lpItemId
     * @param int $lpId
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     *
     * @return LogEventLp|null
     */
    public function getLastRequiredAttemptInLearningPath($userId, $lpItemId, $lpId)
    {
        $query = Database::getManager()
            ->createQuery(
                'SELECT log FROM ChamiloPluginBundle:WhispeakAuth\LogEventLp log
                WHERE
                    log.user = :user AND
                    log.lp = :lp AND
                    log.lpItem = :lp_item AND
                    log.actionStatus = :action_status
                ORDER BY log.datetime DESC'
            )
            ->setMaxResults(1)
            ->setParameters(
                [
                    'user' => $userId,
                    'lp' => $lpId,
                    'lp_item' => $lpItemId,
                    'action_status' => LogEvent::STATUS_REQUIRED,
                ]
            );

        /** @var LogEventLp|null $logEvent */
        $logEvent = $query->getOneOrNullResult();

        return $logEvent;
    }

    /**
     * @param int $userId
     * @param int $lpItemId
     * @param int $lpId
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     *
     * @return LogEventLp|null
     */
    public function addAttemptInLearningPath($userId, $lpItemId, $lpId)
    {
        $em = Database::getManager();

        $user = api_get_user_entity($userId);
        $lpItem = $em->find('ChamiloCourseBundle:CLpItem', $lpItemId);
        $lp = $em->find('ChamiloCourseBundle:CLp', $lpId);

        if (empty($lp) || empty($lpItem)) {
            return null;
        }

        $logEvent = new LogEventLp();
        $logEvent
            ->setLpItem($lpItem)
            ->setLp($lp)
            ->setUser($user)
            ->setDatetime(
                api_get_utc_datetime(null, false, true)
            )
            ->setActionStatus($logEvent::STATUS_REQUIRED);

        $em->persist($logEvent);
        $em->flush();

        return $logEvent;
    }

    /**
     * @param int $status
     * @param int $userId
     * @param int $lpItemId
     * @param int $lpId
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\OptimisticLockException
     *
     * @return LogEventLp|null
     */
    public function updateAttemptInLearningPath($status, $userId, $lpItemId, $lpId)
    {
        $em = Database::getManager();

        $logEvent = $this->getLastRequiredAttemptInLearningPath($userId, $lpItemId, $lpId);

        if (empty($logEvent)) {
            return null;
        }

        if ($logEvent->getActionStatus() !== $status) {
            $logEvent->setActionStatus($status);

            $em->persist($logEvent);
            $em->flush();
        }

        return $logEvent;
    }

    /**
     * @param int $userId
     * @param int $questionId
     * @param int $quizId
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     *
     * @return LogEventQuiz|null
     */
    public function getLastRequiredAttemptInQuiz($userId, $questionId, $quizId)
    {
        $query = Database::getManager()
            ->createQuery(
                'SELECT log FROM ChamiloPluginBundle:WhispeakAuth\LogEventQuiz log
                WHERE
                    log.user = :user AND
                    log.quiz = :quiz AND
                    log.question = :question AND
                    log.actionStatus = :action_status
                ORDER BY log.datetime DESC'
            )
            ->setMaxResults(1)
            ->setParameters(
                [
                    'user' => $userId,
                    'quiz' => $quizId,
                    'question' => $questionId,
                    'action_status' => LogEvent::STATUS_REQUIRED,
                ]
            );

        /** @var LogEventQuiz|null $logEvent */
        $logEvent = $query->getOneOrNullResult();

        return $logEvent;
    }

    /**
     * @param int $userId
     * @param int $questionId
     * @param int $quizId
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     *
     * @return LogEventQuiz|null
     */
    public function addAttemptInQuiz($userId, $questionId, $quizId)
    {
        $em = Database::getManager();

        $user = api_get_user_entity($userId);
        $question = $em->find('ChamiloCourseBundle:CQuizQuestion', $questionId);
        $quiz = $em->find('ChamiloCourseBundle:CQuiz', $quizId);

        if (empty($quiz) || empty($question)) {
            return null;
        }

        $logEvent = new LogEventQuiz();
        $logEvent
            ->setQuestion($question)
            ->setQuiz($quiz)
            ->setUser($user)
            ->setDatetime(
                api_get_utc_datetime(null, false, true)
            )
            ->setActionStatus($logEvent::STATUS_REQUIRED);

        $em->persist($logEvent);
        $em->flush();

        return $logEvent;
    }

    /**
     * @param int $status
     * @param int $userId
     * @param int $questionId
     * @param int $quizId
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\OptimisticLockException
     *
     * @return LogEventQuiz|null
     */
    public function updateAttemptInQuiz($status, $userId, $questionId, $quizId)
    {
        $logEvent = $this->getLastRequiredAttemptInQuiz($userId, $questionId, $quizId);

        if (empty($logEvent)) {
            return null;
        }

        if ($logEvent->getActionStatus() !== $status) {
            $logEvent->setActionStatus($status);

            $em = Database::getManager();
            $em->persist($logEvent);
            $em->flush();
        }

        return $logEvent;
    }

    /**
     * @param int $lpId
     * @param int $userId
     *
     * @throws \Doctrine\ORM\Query\QueryException
     *
     * @return string
     */
    public static function countAllAttemptsInLearningPath($lpId, $userId)
    {
        $query = Database::getManager()
            ->createQuery(
                'SELECT COUNT(log) AS c FROM ChamiloPluginBundle:WhispeakAuth\LogEventLp log
                WHERE log.lp = :lp AND log.user = :user'
            )
            ->setParameters(['lp' => $lpId, 'user' => $userId]);

        $totalCount = (int) $query->getSingleScalarResult();

        return $totalCount;
    }

    /**
     * @param int $lpId
     * @param int $userId
     *
     * @throws \Doctrine\ORM\Query\QueryException
     *
     * @return string
     */
    public static function countSuccessAttemptsInLearningPath($lpId, $userId)
    {
        $query = Database::getManager()
            ->createQuery(
                'SELECT COUNT(log) AS c FROM ChamiloPluginBundle:WhispeakAuth\LogEventLp log
                WHERE log.lp = :lp AND log.user = :user AND log.actionStatus = :status'
            )
            ->setParameters(['lp' => $lpId, 'user' => $userId, 'status' => LogEvent::STATUS_SUCCESS]);

        $totalCount = (int) $query->getSingleScalarResult();

        return $totalCount;
    }

    /**
     * @param int $quizId
     * @param int $userId
     *
     * @throws \Doctrine\ORM\Query\QueryException
     *
     * @return string
     */
    public static function countAllAttemptsInQuiz($quizId, $userId)
    {
        $query = Database::getManager()
            ->createQuery(
                'SELECT COUNT(log) AS c FROM ChamiloPluginBundle:WhispeakAuth\LogEventQuiz log
                WHERE log.quiz = :quiz AND log.user = :user'
            )
            ->setParameters(['quiz' => $quizId, 'user' => $userId]);

        $totalCount = (int) $query->getSingleScalarResult();

        return $totalCount;
    }

    /**
     * @param int $quizId
     * @param int $userId
     *
     * @throws \Doctrine\ORM\Query\QueryException
     *
     * @return string
     */
    public static function countSuccessAttemptsInQuiz($quizId, $userId)
    {
        $query = Database::getManager()
            ->createQuery(
                'SELECT COUNT(log) AS c FROM ChamiloPluginBundle:WhispeakAuth\LogEventQuiz log
                WHERE log.quiz = :quiz AND log.user = :user AND log.actionStatus = :status'
            )
            ->setParameters(['quiz' => $quizId, 'user' => $userId, 'status' => LogEvent::STATUS_SUCCESS]);

        $totalCount = (int) $query->getSingleScalarResult();

        return $totalCount;
    }

    /**
     * Install extra fields for user, learning path and quiz question.
     */
    private function installExtraFields()
    {
        UserManager::create_extra_field(
            self::EXTRAFIELD_AUTH_UID,
            \ExtraField::FIELD_TYPE_TEXT,
            $this->get_lang('Whispeak uid'),
            ''
        );

        LpItem::createExtraField(
            self::EXTRAFIELD_LP_ITEM,
            \ExtraField::FIELD_TYPE_CHECKBOX,
            $this->get_lang('MarkForSpeechAuthentication'),
            '0',
            true,
            true
        );

        $extraField = new \ExtraField('question');
        $params = [
            'variable' => self::EXTRAFIELD_QUIZ_QUESTION,
            'field_type' => \ExtraField::FIELD_TYPE_CHECKBOX,
            'display_text' => $this->get_lang('MarkForSpeechAuthentication'),
            'default_value' => '0',
            'changeable' => true,
            'visible_to_self' => true,
            'visible_to_others' => false,
        ];

        $extraField->save($params);
    }

    /**
     * Install the Doctrine's entities.
     */
    private function installEntities()
    {
        $pluginEntityPath = $this->getEntityPath();

        if (!is_dir($pluginEntityPath)) {
            if (!is_writable(dirname($pluginEntityPath))) {
                Display::addFlash(
                    Display::return_message(get_lang('ErrorCreatingDir').": $pluginEntityPath", 'error')
                );

                return;
            }

            mkdir($pluginEntityPath, api_get_permissions_for_new_directories());
        }

        $fs = new Filesystem();
        $fs->mirror(__DIR__.'/Entity/', $pluginEntityPath, null, ['override']);

        $schema = Database::getManager()->getConnection()->getSchemaManager();

        if (false === $schema->tablesExist('whispeak_log_event')) {
            $sql = "CREATE TABLE whispeak_log_event (
                    id INT AUTO_INCREMENT NOT NULL,
                    user_id INT NOT NULL,
                    lp_item_id INT DEFAULT NULL,
                    lp_id INT DEFAULT NULL,
                    question_id INT DEFAULT NULL,
                    quiz_id INT DEFAULT NULL,
                    datetime DATETIME NOT NULL,
                    action_status SMALLINT NOT NULL,
                    discr VARCHAR(255) NOT NULL,
                    INDEX IDX_A5C4B9FFA76ED395 (user_id),
                    INDEX IDX_A5C4B9FFDBF72317 (lp_item_id),
                    INDEX IDX_A5C4B9FF68DFD1EF (lp_id),
                    INDEX IDX_A5C4B9FF1E27F6BF (question_id),
                    INDEX IDX_A5C4B9FF853CD175 (quiz_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB";
            Database::query($sql);
            $sql = "ALTER TABLE whispeak_log_event ADD CONSTRAINT FK_A5C4B9FFA76ED395
                FOREIGN KEY (user_id) REFERENCES user (id)";
            Database::query($sql);
            $sql = "ALTER TABLE whispeak_log_event ADD CONSTRAINT FK_A5C4B9FFDBF72317
                FOREIGN KEY (lp_item_id) REFERENCES c_lp_item (iid)";
            Database::query($sql);
            $sql = "ALTER TABLE whispeak_log_event ADD CONSTRAINT FK_A5C4B9FF68DFD1EF
                FOREIGN KEY (lp_id) REFERENCES c_lp (iid)";
            Database::query($sql);
            $sql = "ALTER TABLE whispeak_log_event ADD CONSTRAINT FK_A5C4B9FF1E27F6BF
                FOREIGN KEY (question_id) REFERENCES c_quiz_question (iid)";
            Database::query($sql);
            $sql = "ALTER TABLE whispeak_log_event ADD CONSTRAINT FK_A5C4B9FF853CD175
                FOREIGN KEY (quiz_id) REFERENCES c_quiz (iid)";
            Database::query($sql);
        }
    }

    /**
     * Uninstall extra fields for user, learning path and quiz question.
     */
    private function uninstallExtraFields()
    {
        $em = Database::getManager();

        $authIdExtrafield = self::getAuthUidExtraField();

        if (!empty($authIdExtrafield)) {
            $em
                ->createQuery('DELETE FROM ChamiloCoreBundle:ExtraFieldValues efv WHERE efv.field = :field')
                ->execute(['field' => $authIdExtrafield]);

            $em->remove($authIdExtrafield);
            $em->flush();
        }

        $lpItemExtrafield = self::getLpItemExtraField();

        if (!empty($lpItemExtrafield)) {
            $em
                ->createQuery('DELETE FROM ChamiloCoreBundle:ExtraFieldValues efv WHERE efv.field = :field')
                ->execute(['field' => $lpItemExtrafield]);

            $em->remove($lpItemExtrafield);
            $em->flush();
        }

        $quizQuestionExtrafield = self::getQuizQuestionExtraField();

        if (!empty($quizQuestionExtrafield)) {
            $em
                ->createQuery('DELETE FROM ChamiloCoreBundle:ExtraFieldValues efv WHERE efv.field = :field')
                ->execute(['field' => $quizQuestionExtrafield]);

            $em->remove($quizQuestionExtrafield);
            $em->flush();
        }
    }

    /**
     * Uninstall the Doctrine's entities.
     */
    private function uninstallEntities()
    {
        $pluginEntityPath = $this->getEntityPath();

        $fs = new Filesystem();

        if ($fs->exists($pluginEntityPath)) {
            $fs->remove($pluginEntityPath);
        }

        $table = Database::get_main_table('whispeak_log_event');
        $sql = "DROP TABLE IF EXISTS $table";
        Database::query($sql);
    }
}
