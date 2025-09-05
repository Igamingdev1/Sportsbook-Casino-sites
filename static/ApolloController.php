<?php

namespace VanguardLTE\Http\Controllers\Web\Frontend\New_API {

    use \VanguardLTE\User;
    use \VanguardLTE\ApolloGames;
    use \VanguardLTE\ApolloTransaction;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Database\Eloquent\Builder;

    class ApolloController extends \VanguardLTE\Http\Controllers\Controller
    {

        private static $endpoint = 'http://tbs2api.dark-a.com/API/';
        private static $hallID = '3200725';
        private static $hallKey = '123456';

        public function __construct()
        {
        }

        public function callback(\Illuminate\Http\Request $request)
        {
            $message = $request->getContent();
            $message = json_decode($message, true);

            if ($message['hall'] !== self::$hallID || (!isset($message['key']) && !isset($message['sign']))) {
                throw new \Exception('Invalid Hall');
            }
            if (isset($message['key']) && $message['key'] !== self::$hallKey) {
                throw new \Exception('Invalid Key');
            } else if (isset($message['sign']) && $message['sign'] == $this->sign($message)) {
                throw new \Exception('Invalid Sign');
            }

            switch ($message['cmd']) {
                case "getBalance":
                    return $this->getBalance($message);
                case "writeBet":
                    return $this->writeBet($message);
                default:
                    break;
            }
        }

        public function getBalance($req)
        {
            $user = User::find($req['login']);

            if (!$user) {
                return [
                    "status" => 'fail',
                    'error' => 'user_not_found'
                ];
            };
            $user_id = "" . $user->id . "";
            $user_balance = "" . number_format($user->balance, 2, '.', '') . "";
            $user_currency = "" . $this->currency($user) . "";
            return [
                "status" => 'success',
                'error' => '',
                'login' => $user_id,
                'balance' => $user_balance,
                'currency' => $user_currency,
            ];
        }

        private function sign($data)
        {
            unset($data['sign']);
            ksort($data, SORT_STRING);
            array_push($data, self::$hallKey);
            $data = implode(':', $data);
            $sign = hash('sha256', $data);
            return $sign;
        }

        private function currency($user)
        {
            return "EUR";
        }

        private function operationId()
        {
            return rand(100, 9999999999);
        }

        public function writeBet($req)
        {


            $user = User::find($req['login']);

            if (!$user) {
                return [
                    "status" => 'fail',
                    'error' => 'user_not_found'
                ];
            };

            if ($user->balance < $req['bet']) {
                return [
                    'status' => 'fail',
                    'error' => 'fail_balance'
                ];
            }

            $rdata = [
                "status" => 'success',
                'error' => '',
                'login' => $user->id,
                'operationId' => $this->operationId()
            ];
            if ($req['bet'] != 0) {

                $trans = new ApolloTransaction;
                $trans->userId = $req['login'];
                $trans->bet = floatval(-$req['bet']);
                $trans->win = $req['win'];
                $trans->gameId = $req['gameId'];
                $trans->user_credit = $user->balance - $req['bet'] + $req['win'];
                if (isset($req['tradeId'])) $trans->tradeId = $req['tradeId'];
                if (isset($req['betInfo'])) $trans->betInfo = $req['betInfo'];
                if (isset($req['matrix'])) $trans->matrix = $req['matrix'];
                if (isset($req['date'])) $trans->date = $req['date'];
                if (isset($req['WinLines'])) $trans->WinLines = $req['WinLines'];
                if (isset($req['sessionId'])) $trans->sessionId = $req['sessionId'];

                $trans->balance_before = $user->balance;
                $trans->balance_after = $user->balance - $req['bet'] + $req['win'];

                try {
                    $saved = $trans->save();
                    $this->jackpot_updates($user->id, $trans->bet);
                } catch (\Exception $e) {
                }
            }

            $balance = $this->changeBalance($req['login'], (float)$req['bet'], (float)$req['win']);
            $rdata['balance'] = number_format($balance, 2, '.', '');
            $rdata['currency'] = $this->currency($user);
            return $rdata;
        }

        public function changeBalance($userid, $bet, $win)
        {
            $user = User::find($userid);
            $user->increment('balance', $win - $bet);
            return $user->balance;
        }

        public function initGames()
        {
            $reqParams = [
                'cmd' => 'gamesList',
                'hall' => self::$hallID,
                'key' => self::$hallKey,
                'cdnUrl' => '',
            ];
            ini_set('max_execution_time', 300);
            $res = Http::post(self::$endpoint, $reqParams)->json($key = null);
            if ($res['status'] == 'success') {

                \VanguardLTE\ApolloGames::truncate();

                foreach ($res['content']['gameList'] as $game) {
                    \VanguardLTE\ApolloGames::create([
                        'gameId' => $game['id'],
                        'name' => $game['name'],
                        'img' => $game['img'],
                        'device' => $game['device'],
                        'title' => $game['title'],
                        'categories' => $game['categories'],
                        'flash' => $game['flash'],
                    ]);
                }
                return 'true';
            }
            return 'false';
        }


        public function getGame(\Illuminate\Http\Request $request, $gameId)
        {
            $openGameUserId = "" . auth()->user()->id . "";
            $reqParams = [
                "cmd" => "openGame",
                "hall" => self::$hallID,
                "domain" => 'https://habanero.live',
                "exitUrl" => redirect()->back()->getTargetUrl(),
                "language" => "en",
                "key" => self::$hallKey,
                "login" => $openGameUserId,
                "gameId" => $gameId,
                "cdnUrl" => "",
                "demo" => "0"
            ];

            $res = Http::post(self::$endpoint . 'openGame/', $reqParams)->json($key = null);

            if ($res['status'] == 'success') {
                return redirect($res['content']['game']['url']);
            }
        }

        public function jackpot_updates($userId, $bet)
        {
            $shop_id = \VanguardLTE\User::find($userId)->parent->id;
            $jpg1_percent = \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG1')->first()->percent;
            $jpg2_percent = \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG2')->first()->percent;
            $jpg3_percent = \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG3')->first()->percent;
            $jpg1_balanceFirst = \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG1')->first()->balance;
            $jpg2_balanceFirst = \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG2')->first()->balance;
            $jpg3_balanceFirst = \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG3')->first()->balance;
            $new_jpg1_balance = $bet * $jpg1_percent / 100;
            $new_jpg2_balance = $bet * $jpg2_percent / 100;
            $new_jpg3_balance = $bet * $jpg3_percent / 100;
            $newjpg1_balance = floatval($jpg1_balanceFirst + $new_jpg1_balance);
            $newjpg2_balance = floatval($jpg2_balanceFirst + $new_jpg2_balance);
            $newjpg3_balance = floatval($jpg3_balanceFirst + $new_jpg3_balance);
            \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG1')->update(['balance' => $newjpg1_balance]);
            \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG2')->update(['balance' => $newjpg2_balance]);
            \VanguardLTE\JPG::where('user_id', $shop_id)->where('name', 'JPG3')->update(['balance' => $newjpg3_balance]);
        }
    }
}
