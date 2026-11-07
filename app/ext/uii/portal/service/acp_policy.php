<?php
namespace uii\portal\service;
final class acp_policy
{
    // Remove only modules for features disabled by this portal. Core services stay installed.
    const UNUSED=[
        'acp_board'=>['avatar','message','signature','feed','registration','email'],
        'acp_users'=>['profile','prefs','avatar','rank','sig','groups'],
        'acp_groups'=>null,'acp_bbcodes'=>null,'acp_icons'=>null,'acp_ranks'=>null,'acp_bots'=>null,
        'acp_email'=>null,'acp_jabber'=>null,'acp_profile'=>null,'acp_contact'=>null,
        'acp_prune'=>null,'acp_inactive'=>null,
    ];
    public static function disabled(string $module, string $mode): bool
    {
        if (strpos($module,'acp_')!==0) { $module='acp_'.$module; }
        return array_key_exists($module,self::UNUSED) && (self::UNUSED[$module]===null || in_array($mode,self::UNUSED[$module],true));
    }
}
