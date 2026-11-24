<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

use PDO;
use InvalidArgumentException;

/**
 * M3-B in-process account-to-private-source scope adapter.
 * The caller MUST pass server-owned Steam session, never URL/user POST fields.
 * The scope is an opaque HMAC, never plaintext user ID, name or SteamID.
 */
final class IdentityScopeResolver
{
    public static function fromSession(PDO $pdo,array $trustedServerSession,string $serverScopeKey):array
    {
        if(strlen($serverScopeKey)<32)throw new InvalidArgumentException('TRUSTED_SCOPE_KEY_REQUIRED');
        $identity=IdentityReader::resolve($pdo,$trustedServerSession);
        if(($identity['status']??null)!=='VERIFIED'){
            return ['status'=>'IDENTITY_UNVERIFIED','opaque_scope'=>null];
        }
        $subject=$identity['subject'];
        $uid=(int)$subject['user_id'];
        $steam=(string)$trustedServerSession['steam_id'];
        $region=(string)$subject['region'];
        if($uid<=0 || !in_array($region,['TW','JP'],true)
            || preg_match('/^\d{17}$/D',$steam)!==1){
            return ['status'=>'IDENTITY_UNVERIFIED','opaque_scope'=>null];
        }
        $scope=hash_hmac('sha256',SourceProvenance::canonical([
            'scope_version'=>'checkpoint2026/identity-scope-v1',
            'user_id'=>$uid,
            'steam_id'=>$steam,
            'region'=>$region,
        ]),$serverScopeKey);
        return ['status'=>'VERIFIED','opaque_scope'=>$scope];
    }
}
