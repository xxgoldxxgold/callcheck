import Fastify from 'fastify';
import fastifyWs from '@fastify/websocket';
import fastifyFormBody from '@fastify/formbody';
import WebSocket from 'ws';
import dotenv from 'dotenv';

dotenv.config();

const PORT = parseInt(process.env.PORT || '8080', 10);
const OPENAI_API_KEY = process.env.OPENAI_API_KEY;
const CALLBACK_URL = process.env.CALLBACK_URL;
const CALLBACK_SECRET = process.env.CALLBACK_SECRET;
const OPENAI_VOICE = process.env.OPENAI_VOICE || 'shimmer';

/* 営業確認用: miniモデルで高速応答 */
const OPENAI_MODEL = process.env.OPENAI_MODEL || 'gpt-4o-mini-realtime-preview-2024-12-17';

/* 予約用: フルモデルで正確な会話・丁寧なやり取りが必要 */
const OPENAI_MODEL_FULL = 'gpt-4o-realtime-preview-2024-12-17';

if (!OPENAI_API_KEY) { console.error('OPENAI_API_KEY missing'); process.exit(1); }
if (!CALLBACK_URL) { console.error('CALLBACK_URL missing'); process.exit(1); }
if (!CALLBACK_SECRET) { console.error('CALLBACK_SECRET missing'); process.exit(1); }

const SYSTEM_INSTRUCTIONS = `あなたは日本のお店に電話で営業確認するAIです。

手順:
1. 「すみません。今営業されてますか？」と聞く
2. 相手の返答を最後まで聞いて判定（もしもし＝営業中）
3. 【必須】営業中と判定したら、必ず「何時まで営業されてますか？」と声に出して聞け。この質問を飛ばすな。
4. 相手が営業時間を答え終わったら、report_resultを呼ぶ（相手の言葉をそのまま記録）
5. 「ありがとうございました。失礼いたします」で終了

ルール:
- ★極めて早口で話せ。通常の2倍速で話せ。テキパキと手短に。間を空けるな。一言も無駄にするな
- 敬語で簡潔に。余計な前置き不要
- 相手が話し終わるまで待つ
- 営業中の場合、営業時間を聞く前にreport_resultを呼ぶな。絶対に先に営業時間を聞け。
- 休業/不明の場合はそのままreport_resultを呼んでよい
- 無応答5秒で「もしもし？」→それでも無応答ならno_responseで報告
- 時刻の聞き取りは特に正確に。相手の言葉をそのまま記録せよ
- 「2時」と「10時」、「1時」と「7時」等、紛らわしい数字を聞き間違えるな
- hours_answerには相手が言った言葉をそのまま入れろ。勝手に変換するな`;

/* === 韓国語: 営業確認用 === */
const SYSTEM_INSTRUCTIONS_KO = `당신은 한국 가게에 전화로 영업 확인을 하는 AI입니다.

절차:
1. "안녕하세요, 지금 영업 중이신가요?"라고 물어본다
2. 상대방의 대답을 끝까지 듣고 판단한다 (여보세요 = 영업 중)
3. 【필수】영업 중이라고 판단되면, 반드시 "몇 시까지 영업하시나요?"라고 소리 내어 물어라. 이 질문을 건너뛰지 마라.
4. 상대방이 영업시간을 답한 후, report_result를 호출한다 (상대방의 말을 그대로 기록)
5. "감사합니다. 수고하세요"로 종료

규칙:
- ★매우 빠르게 말해라. 보통의 2배 속도로 말해라. 간결하게 끝내라
- 존댓말로 간결하게. 불필요한 서두 없이
- 상대방이 말을 끝낼 때까지 기다려라
- 영업 중일 경우, 영업시간을 묻기 전에 report_result를 호출하지 마라. 반드시 먼저 영업시간을 물어라
- 휴업/불명의 경우는 그대로 report_result를 호출해도 된다
- 무응답 5초면 "여보세요?" → 그래도 무응답이면 no_response로 보고
- 시간 듣기는 특히 정확하게. 상대방의 말을 그대로 기록하라
- hours_answer에는 상대방이 말한 그대로 넣어라. 임의로 변환하지 마라`;

const TOOLS = [{
  type: 'function',
  name: 'report_result',
  description: '営業確認結果を報告。相手の言葉をそのまま記録。',
  parameters: {
    type: 'object',
    properties: {
      open_status: { type: 'string', enum: ['open','closed','unknown','no_response'], description: '営業状況' },
      open_answer: { type: 'string', description: '相手の営業確認への回答（そのまま）' },
      hours_answer: { type: 'string', description: '相手の営業時間への回答（そのまま）。未確認なら空文字' },
      hours_end: { type: 'string', description: '閉店時刻HH:MM。不明なら空文字' },
      hours_start: { type: 'string', description: '開店時刻HH:MM。不明なら空文字' },
      summary: { type: 'string', description: '要約1文' }
    },
    required: ['open_status', 'open_answer']
  }
}];

/* === 予約用プロンプト・ツール === */

const RESERVATION_TOOLS = [{
  type: 'function',
  name: 'report_reservation_result',
  description: '予約結果を報告する',
  parameters: {
    type: 'object',
    properties: {
      reservation_status: { type: 'string', enum: ['confirmed', 'rejected', 'unknown', 'no_response'], description: '予約状況' },
      confirmation: { type: 'string', description: '予約確定時の詳細（日時、人数の復唱など）' },
      rejection_reason: { type: 'string', description: '予約不可の理由' },
      alternative_suggestion: { type: 'string', description: '店側が提案した代替案' },
      summary: { type: 'string', description: '要約1文' }
    },
    required: ['reservation_status', 'summary']
  }
}];

/* 時刻を自然な日本語に変換（電話で聞き取りやすい表現） */
function formatTimeNatural(timeStr) {
  if (!timeStr) return '不明';
  const m = timeStr.match(/^(\d{1,2}):(\d{2})$/);
  if (!m) return timeStr;
  const h = parseInt(m[1], 10);
  const min = parseInt(m[2], 10);
  const minStr = min > 0 ? `${min}分` : '';
  let prefix = '';
  if (h === 0) prefix = '深夜';
  else if (h >= 1 && h <= 5) prefix = '深夜';
  else if (h >= 6 && h <= 10) prefix = '朝';
  else if (h >= 11 && h <= 13) prefix = '昼';
  else if (h >= 22) prefix = '夜';
  if (h === 0 && min === 0) return '深夜12時';
  if (h === 0) return `深夜12時${minStr}`;
  return `${prefix}${h}時${minStr}`;
}

/* 時刻を12時間AM/PM表記に変換 ("20:30" → "8:30 PM") */
function formatTime12h(timeStr) {
  if (!timeStr) return 'unknown';
  const m = timeStr.match(/^(\d{1,2}):(\d{2})$/);
  if (!m) return timeStr;
  let h = parseInt(m[1], 10);
  const min = m[2];
  const ampm = h >= 12 ? 'PM' : 'AM';
  if (h > 12) h -= 12;
  if (h === 0) h = 12;
  return min === '00' ? `${h} ${ampm}` : `${h}:${min} ${ampm}`;
}

/* 分数を12時間AM/PM表記に変換 (1230 → "8:30 PM") */
function formatMinutes12h(totalMin) {
  const hh = Math.floor(((totalMin % 1440) + 1440) % 1440 / 60);
  const mm = ((totalMin % 1440) + 1440) % 1440 % 60;
  const ampm = hh >= 12 ? 'PM' : 'AM';
  let h12 = hh > 12 ? hh - 12 : (hh === 0 ? 12 : hh);
  return mm === 0 ? `${h12} ${ampm}` : `${h12}:${String(mm).padStart(2, '0')} ${ampm}`;
}

/* 日本語日付をEnglish日付に変換 ("2月22日(日)" → "February 22nd (Sunday)") */
function formatDateFromJapanese(dateStr) {
  if (!dateStr) return 'unknown';
  const m = dateStr.match(/(\d{1,2})月(\d{1,2})日(?:\((.)\))?/);
  if (!m) return dateStr;
  const month = parseInt(m[1], 10);
  const day = parseInt(m[2], 10);
  const dowJa = m[3];
  const months = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
  const dowMap = {'日':'Sunday','月':'Monday','火':'Tuesday','水':'Wednesday',
                  '木':'Thursday','金':'Friday','土':'Saturday'};
  const suffix = (day === 1 || day === 21 || day === 31) ? 'st' :
                 (day === 2 || day === 22) ? 'nd' :
                 (day === 3 || day === 23) ? 'rd' : 'th';
  let result = `${months[month - 1]} ${day}${suffix}`;
  if (dowJa && dowMap[dowJa]) result += ` (${dowMap[dowJa]})`;
  return result;
}

/* 電話番号をハイフン区切りに変換 ("09075368115" → "090-7536-8115") */
function formatPhoneReadable(phone) {
  if (!phone) return '不明';
  const digits = phone.replace(/\D/g, '');
  if (/^0[789]0\d{8}$/.test(digits)) {
    return digits.slice(0,3) + '-' + digits.slice(3,7) + '-' + digits.slice(7);
  }
  if (/^0\d{9}$/.test(digits)) {
    return digits.slice(0,2) + '-' + digits.slice(2,6) + '-' + digits.slice(6);
  }
  if (/^0120\d{6}$/.test(digits)) {
    return digits.slice(0,4) + '-' + digits.slice(4,7) + '-' + digits.slice(7);
  }
  return phone;
}

/* 電話番号をカタカナ読みに変換 */
function formatPhonePhonetic(phone) {
  if (!phone) return '不明';
  const digitMap = { '0':'ゼロ', '1':'イチ', '2':'ニー', '3':'サン', '4':'ヨン', '5':'ゴー', '6':'ロク', '7':'ナナ', '8':'ハチ', '9':'きゅう' };
  const digits = phone.replace(/\D/g, '');
  return digits.split('').map(d => digitMap[d] || d).join(' ');
}

function formatMinutes(m) {
  if (m < 60) return m + '分';
  const h = Math.floor(m / 60);
  const r = m % 60;
  return r > 0 ? h + '時間' + r + '分' : h + '時間';
}

function buildReservationInstructions(params) {
  const readableTime = formatTimeNatural(params.rsv_time);
  const readablePhone = formatPhoneReadable(params.rsv_phone);
  const phoneticPhone = formatPhonePhonetic(params.rsv_phone);
  const isFlexible = params.rsv_flexible === '1';
  const flexBefore = parseInt(params.rsv_flex_before || '60', 10);
  const flexAfter = parseInt(params.rsv_flex_after || '60', 10);
  const rsvDateRaw = params.rsv_date || '不明';
  /* 「2月」→「にがつ」等の読み間違い防止: 漢数字月をひらがなに置換 */
  const monthReadings = {'1月':'いちがつ','2月':'にがつ','3月':'さんがつ','4月':'しがつ','5月':'ごがつ','6月':'ろくがつ','7月':'しちがつ','8月':'はちがつ','9月':'くがつ','10月':'じゅうがつ','11月':'じゅういちがつ','12月':'じゅうにがつ'};
  let rsvDate = rsvDateRaw;
  for (const [kanji, reading] of Object.entries(monthReadings)) {
    if (rsvDateRaw.includes(kanji)) {
      rsvDate = rsvDateRaw.replace(kanji, reading);
      break;
    }
  }
  /* 曜日の括弧を自然な読みに変換: (金) → きんようび */
  const dayReadings = {'(月)':'げつようび','(火)':'かようび','(水)':'すいようび','(木)':'もくようび','(金)':'きんようび','(土)':'どようび','(日)':'にちようび'};
  for (const [bracket, dayReading] of Object.entries(dayReadings)) {
    if (rsvDate.includes(bracket)) {
      rsvDate = rsvDate.replace(bracket, dayReading);
      break;
    }
  }
  const rsvPartySize = params.rsv_party_size || '不明';
  const rsvName = params.rsv_name || '不明';

  /* フレックス範囲の具体的な時刻を計算 */
  let flexRangeStart = '';
  let flexRangeEnd = '';
  let flexExamplesOK = '';
  let flexExamplesNG = '';
  if (isFlexible && params.rsv_time) {
    const tm = params.rsv_time.match(/^(\d{1,2}):(\d{2})$/);
    if (tm) {
      const baseMin = parseInt(tm[1], 10) * 60 + parseInt(tm[2], 10);
      const startMin = baseMin - flexBefore;
      const endMin = baseMin + flexAfter;
      const fmtTime = (m) => {
        const hh = Math.floor(((m % 1440) + 1440) % 1440 / 60);
        const mm = ((m % 1440) + 1440) % 1440 % 60;
        return `${hh}:${String(mm).padStart(2, '0')}`;
      };
      const fmtTimeJa = (m) => {
        const hh = Math.floor(((m % 1440) + 1440) % 1440 / 60);
        const mm = ((m % 1440) + 1440) % 1440 % 60;
        return `${hh}時${mm > 0 ? mm + '分' : ''}`;
      };
      flexRangeStart = fmtTimeJa(startMin);
      flexRangeEnd = fmtTimeJa(endMin);
      /* OK例: 範囲内のいくつかの時刻 */
      const okTimes = [];
      okTimes.push(fmtTime(startMin));
      okTimes.push(fmtTime(startMin + 30));
      if (baseMin !== startMin && baseMin !== endMin) okTimes.push(fmtTime(baseMin));
      okTimes.push(fmtTime(endMin - 20));
      okTimes.push(fmtTime(endMin));
      flexExamplesOK = [...new Set(okTimes)].join(', ');
      /* NG例: 範囲外の時刻 */
      const ngTimes = [];
      ngTimes.push(fmtTime(startMin - 10));
      ngTimes.push(fmtTime(endMin + 10));
      ngTimes.push(fmtTime(endMin + 30));
      flexExamplesNG = [...new Set(ngTimes)].join(', ');
    }
  }

  const flexDesc = isFlexible
    ? `可（${flexRangeStart}〜${flexRangeEnd}の範囲のみ受け入れ可）`
    : '不可（指定時間ぴったりが空いていなければ予約せず終了する）';

  const flexStep = isFlexible
    ? `4. 指定時間が空いていない場合、「${flexRangeStart}から${flexRangeEnd}くらいの間で空いている時間はありますか？」と聞く。
   ★★★絶対厳守: 受け入れ可能な時間帯は【${flexRangeStart}〜${flexRangeEnd}】のみ。
   具体例:
     OK（予約してよい）→ ${flexExamplesOK}
     NG（断れ）→ ${flexExamplesNG}
   判定方法: 提案された時刻が${flexRangeStart}以降かつ${flexRangeEnd}以前なら受け入れろ。
   例えば13時40分は14時より前なのでOK。14時10分は14時より後なのでNG。
   ★相手が提案した時間を正しく判定できないなら、受け入れろ。断って失礼するよりマシだ。
   範囲外の時間しかなければ「わかりました、ではまた改めます。失礼します」と言ってrejectedで報告する。`
    : '4. 指定時間が空いていない場合、「わかりました、ではまた改めます。失礼します」と言ってrejectedでreport_reservation_resultを呼び、電話を終了する。代替時間を聞くな';

  return `あなたは日本のお店に電話で予約を入れるAIアシスタントです。

予約情報:
- 日時: ${rsvDate} ${readableTime}
- 人数: ${rsvPartySize}名
- 予約名: ${rsvName}
- 連絡先（数字）: ${readablePhone}
- 連絡先（読み上げ用）: ${phoneticPhone}
- 時間変更: ${flexDesc}

会話の流れ（★各ステップで必ず1つだけ伝えて相手の返事を待て。2つ以上を同じ発言で言うな★）:
1. 「すみません、予約をお願いしたいのですが」とだけ言って、相手の反応を待つ。ここで予約の詳細を言うな。
2. 相手が「はい、どうぞ」「何名様ですか」等と応じたら、「${rsvDate}の${readableTime}から${rsvPartySize}名で予約をお願いしたいんですけど、空いてますか？」と聞く。
   ★日付の読み方: 日付をそのまま自然に読め。ひらがな部分はそのまま読めばよい。
   【重要】この質問をした後、絶対にreport_reservation_resultを呼ぶな。相手の返事を待て。
3. ★相手の返答を最後まで聞く★ 自分が質問した直後に結果を判断するな。相手が「空いてます」「空いてません」等と答えるのを必ず待て。相手が確認中なら黙って待つ（30秒以上でも待つ）
${flexStep}
5. 予約OKの場合:
   - ★時刻変更なし（こちらが希望した時刻のまま）→ 時刻復唱をスキップし、すぐに「名前は${rsvName}です」とだけ伝える。「では」等の接続詞を付けるな。いきなり「名前は」から始めろ。
   - 時刻が変更された場合のみ → 変更後の時刻を「XX時ですね、お願いします」と復唱確認し、相手の返事を待ってから「名前は${rsvName}です」と伝える。
   ★★★時刻変更時の復唱ルール:
   - 相手が言った時刻を正確に聞き取って、必ずそのまま復唱しろ。
   - 19分と9分、20分と29分のような似た数字を絶対に聞き間違えるな。
   ※名前だけ。電話番号はまだ言うな。
7. 相手が名前を復唱・確認するのを待つ
8. 相手の確認後、「電話番号は、${phoneticPhone}、です」と1回だけ読め。★★★1回読んだら即座に黙れ。繰り返すな★★★
9. 電話番号を言い終わったら黙って相手の反応を待て
10. 相手が復唱・確認したら「よろしくお願いします」と言え。★電話番号と同じ発話で言うな。別の発話で言え★
11. 相手が「承りました」「大丈夫です」「お待ちしてます」など最終確認の言葉を言ったら、予約完了。ここで初めてreport_reservation_resultをconfirmedで呼べ
12. report_reservation_result呼び出し後、「ありがとうございました。失礼します」で終了

重要ルール:
- 早口で話せ。通常の2倍速で、テンポよく丁寧に。間を空けるな。一言話し終わったら即座に黙れ。余計な間を入れるな
- 敬語で簡潔に
- 相手が話し終わるまで絶対に割り込むな。沈黙は相手が考え中の可能性がある
- ★★★一度に1つのことだけ伝えろ。絶対に2つ以上の情報を同じ発話で言うな★★★
  悪い例: 「14時でお願いします。名前はたかはしです」← 時刻と名前を同時に言っている。ダメ。
  良い例: 「14時でお願いします」→相手の返事を待つ→「名前はたかはしです」→相手の返事を待つ
- 電話番号を伝える時は「連絡先（読み上げ用）」のカタカナをそのまま一字一字読め。途中で止まるな、全桁を一息で読み切れ。絶対に省略や言いかえをするな
- 名前を伝えた後、相手の反応を待ってから電話番号を伝えろ。同時に言うな
- ★★★最重要: report_reservation_resultの呼び出しルール:
  ■ 絶対禁止: 自分が質問した同じターンでreport_reservation_resultを呼ぶな。「空いてますか？」と聞いた直後に結果を決めるな。必ず相手の返答を聞いてから判断しろ。
  ■ 絶対禁止: 相手の返答を聞く前に予約結果を勝手に推測するな。お前は予約が取れるかどうかを知らない。相手に聞いて初めてわかる。
  ■ rejectedにするタイミング: 相手が「空いてません」「その時間は無理です」等と明確に断った場合のみ。
  ■ confirmedにするタイミング: 名前と電話番号を伝え、「よろしくお願いします」と言い、相手が「承りました」「お待ちしてます」等と最終確認した後のみ。
  ■ 自分が話している最中にreport_reservation_resultを呼ぶな。電話番号を読み上げた同じターンで呼ぶな。必ず相手の返事を聞いてから次のターンで呼べ。
- 無応答10秒で「もしもし？」→さらに10秒無応答ならno_responseで報告
- 何があってもreport_reservation_resultは必ず最後に呼べ。呼ばずに終わるな
- 結果は必ず日本語で記録しろ。英語で書くな。`;

}

/* === 韓国語: 予約用プロンプト === */
function buildReservationInstructionsKo(params) {
  const readableTime = formatTimeKo(params.rsv_time);
  const readablePhone = params.rsv_phone || '불명';
  const phoneticPhone = formatPhonePhoneticKo(params.rsv_phone);
  const isFlexible = params.rsv_flexible === '1';
  const flexBefore = parseInt(params.rsv_flex_before || '60', 10);
  const flexAfter = parseInt(params.rsv_flex_after || '60', 10);
  const rsvDate = params.rsv_date || '불명';
  const rsvPartySize = params.rsv_party_size || '불명';
  const rsvName = params.rsv_name || '불명';

  let flexRangeStart = '';
  let flexRangeEnd = '';
  if (isFlexible && params.rsv_time) {
    const tm = params.rsv_time.match(/^(\d{1,2}):(\d{2})$/);
    if (tm) {
      const baseMin = parseInt(tm[1], 10) * 60 + parseInt(tm[2], 10);
      const fmtTimeKoShort = (m) => {
        const hh = Math.floor(((m % 1440) + 1440) % 1440 / 60);
        const mm = ((m % 1440) + 1440) % 1440 % 60;
        return `${hh}시${mm > 0 ? ' ' + mm + '분' : ''}`;
      };
      flexRangeStart = fmtTimeKoShort(baseMin - flexBefore);
      flexRangeEnd = fmtTimeKoShort(baseMin + flexAfter);
    }
  }

  const flexDesc = isFlexible
    ? `가능 (${flexRangeStart}~${flexRangeEnd} 범위만 수락 가능)`
    : '불가 (지정 시간 정확히 비어 있지 않으면 예약하지 않고 종료)';

  const flexStep = isFlexible
    ? `4. 지정 시간이 안 되는 경우, "${flexRangeStart}부터 ${flexRangeEnd} 사이에 가능한 시간이 있나요?"라고 물어봐라.
   ★★★절대 엄수: 수락 가능한 시간대는 【${flexRangeStart}~${flexRangeEnd}】만.
   범위 밖의 시간밖에 없으면 "알겠습니다, 다음에 다시 연락드리겠습니다. 수고하세요"라고 말하고 rejected로 보고해라.`
    : '4. 지정 시간이 안 되는 경우, "알겠습니다, 다음에 다시 연락드리겠습니다. 수고하세요"라고 말하고 rejected로 report_reservation_result를 호출해라. 대안 시간을 묻지 마라';

  return `당신은 한국 가게에 전화로 예약을 넣는 AI 어시스턴트입니다.

예약 정보:
- 일시: ${rsvDate} ${readableTime}
- 인원: ${rsvPartySize}명
- 예약자 이름: ${rsvName}
- 연락처 (숫자): ${readablePhone}
- 연락처 (읽기용): ${phoneticPhone}
- 시간 변경: ${flexDesc}

대화 흐름 (★각 단계에서 반드시 하나만 전달하고 상대방의 답을 기다려라. 2개 이상을 같은 발화에서 말하지 마라★):
1. "안녕하세요, 예약을 하고 싶은데요"라고만 말하고 상대방의 반응을 기다려라. 여기서 예약 상세를 말하지 마라.
2. 상대방이 "네, 말씀하세요" 등으로 응하면, "${rsvDate} ${readableTime}에 ${rsvPartySize}명 예약 가능한가요?"라고 물어라.
   【중요】이 질문을 한 후, 절대로 report_reservation_result를 호출하지 마라. 상대방의 답을 기다려라.
3. ★상대방의 답을 끝까지 들어라★ 자신이 질문한 직후에 결과를 판단하지 마라.
${flexStep}
5. 예약 OK인 경우:
   - 시간 변경 없음 → 바로 "이름은 ${rsvName}입니다"라고만 전달해라.
   - 시간이 변경된 경우만 → 변경된 시간을 "XX시요, 부탁드립니다"라고 확인하고, 상대방의 답을 기다린 후 "이름은 ${rsvName}입니다"라고 전달해라.
7. 상대방이 이름을 확인하는 것을 기다려라
8. 상대방 확인 후, "전화번호는 ${phoneticPhone} 입니다"라고 1번만 읽어라. ★★★1번 읽으면 즉시 멈춰라. 반복하지 마라★★★
9. 전화번호를 말한 후 조용히 상대방의 반응을 기다려라
10. 상대방이 확인하면 "잘 부탁드립니다"라고 말해라
11. 상대방이 "알겠습니다", "기다리겠습니다" 등 최종 확인을 하면, 예약 완료. 여기서 처음으로 report_reservation_result를 confirmed로 호출해라
12. report_reservation_result 호출 후, "감사합니다. 수고하세요"로 종료

중요 규칙:
- 빠르게 말해라. 보통의 2배 속도로, 템포 있게 정중하게
- 존댓말로 간결하게
- 상대방이 말을 끝낼 때까지 절대 끼어들지 마라
- ★★★한 번에 하나만 전달해라. 절대로 2개 이상의 정보를 같은 발화에서 말하지 마라★★★
- 전화번호를 전달할 때는 "연락처 (읽기용)"의 한국어 읽기를 그대로 한 자씩 읽어라
- ★★★최중요: report_reservation_result 호출 규칙:
  ■ 절대 금지: 자신이 질문한 같은 턴에서 report_reservation_result를 호출하지 마라
  ■ 절대 금지: 상대방의 답을 듣기 전에 예약 결과를 추측하지 마라
  ■ rejected: 상대방이 "안 됩니다" 등 명확히 거절한 경우만
  ■ confirmed: 이름과 전화번호를 전달하고, 상대방이 최종 확인한 후에만
- 무응답 10초면 "여보세요?" → 추가 10초 무응답이면 no_response로 보고
- 무슨 일이 있어도 report_reservation_result는 반드시 마지막에 호출해라
- 결과는 반드시 한국어로 기록해라. 일본어나 영어로 쓰지 마라.`;
}

/* 한국어 시간 포맷 */
function formatTimeKo(timeStr) {
  if (!timeStr) return '불명';
  const m = timeStr.match(/^(\d{1,2}):(\d{2})$/);
  if (!m) return timeStr;
  const h = parseInt(m[1], 10);
  const min = parseInt(m[2], 10);
  const minStr = min > 0 ? ` ${min}분` : '';
  let prefix = '';
  if (h === 0 || (h >= 1 && h <= 5)) prefix = '새벽 ';
  else if (h >= 6 && h <= 11) prefix = '오전 ';
  else if (h === 12) prefix = '낮 ';
  else if (h >= 13 && h <= 17) prefix = '오후 ';
  else if (h >= 18 && h <= 21) prefix = '저녁 ';
  else if (h >= 22) prefix = '밤 ';
  const displayH = h > 12 ? h - 12 : (h === 0 ? 12 : h);
  return `${prefix}${displayH}시${minStr}`;
}

/* 한국어 전화번호 읽기 */
function formatPhonePhoneticKo(phone) {
  if (!phone) return '불명';
  const digitMap = { '0':'공', '1':'일', '2':'이', '3':'삼', '4':'사', '5':'오', '6':'육', '7':'칠', '8':'팔', '9':'구' };
  const digits = phone.replace(/\D/g, '');
  return digits.split('').map(d => digitMap[d] || d).join(' ');
}

/* === 多言語対応: 言語設定マップ === */
const LANG_CONFIG = {
  ja: { name: 'Japanese' },
  ko: { name: 'Korean' },
  en: { name: 'English' },
  zh: { name: 'Chinese Mandarin' },
  yue: { name: 'Cantonese' },
  es: { name: 'Spanish' },
  fr: { name: 'French' },
  de: { name: 'German' },
  it: { name: 'Italian' },
  pt: { name: 'Portuguese' },
  nl: { name: 'Dutch' },
  ru: { name: 'Russian' },
  ar: { name: 'Arabic' },
  hi: { name: 'Hindi' },
  th: { name: 'Thai' },
  vi: { name: 'Vietnamese' },
  id: { name: 'Indonesian' },
  ms: { name: 'Malay' },
  tr: { name: 'Turkish' },
  pl: { name: 'Polish' },
  uk: { name: 'Ukrainian' },
  cs: { name: 'Czech' },
  sv: { name: 'Swedish' },
  da: { name: 'Danish' },
  no: { name: 'Norwegian' },
  fi: { name: 'Finnish' },
  el: { name: 'Greek' },
  ro: { name: 'Romanian' },
  hu: { name: 'Hungarian' },
  he: { name: 'Hebrew' },
  tl: { name: 'Filipino/Tagalog' },
  bn: { name: 'Bengali' },
  ur: { name: 'Urdu' },
  fa: { name: 'Persian/Farsi' },
  sw: { name: 'Swahili' },
  mn: { name: 'Mongolian' },
  km: { name: 'Khmer' },
  lo: { name: 'Lao' },
  ne: { name: 'Nepali' },
  ka: { name: 'Georgian' },
  hy: { name: 'Armenian' },
  az: { name: 'Azerbaijani' },
  uz: { name: 'Uzbek' },
  bg: { name: 'Bulgarian' },
  hr: { name: 'Croatian' },
  sr: { name: 'Serbian' },
  sl: { name: 'Slovenian' },
  sk: { name: 'Slovak' },
  lt: { name: 'Lithuanian' },
  lv: { name: 'Latvian' },
  et: { name: 'Estonian' },
  sq: { name: 'Albanian' },
  is: { name: 'Icelandic' },
  my: { name: 'Burmese' },
  si: { name: 'Sinhala' },
};

function getLangName(lang) {
  return (LANG_CONFIG[lang] || {}).name || lang;
}

/* === 汎用: 営業確認用プロンプト（ja/ko以外の全言語） === */
function buildCheckInstructionsGeneric(lang) {
  const langName = getLangName(lang);
  return `You are an AI calling a store to check if they are currently open.
★★★ CRITICAL: You MUST speak ONLY in ${langName} throughout the ENTIRE call. NEVER use any other language. ★★★

Procedure:
1. Greet politely in ${langName} and ask: "Are you open right now?"
2. Listen to their full response before judging. If they answer the phone (e.g., say "hello"), that means they are open.
3. [MANDATORY] If they are open, you MUST vocally ask: "What time do you close?" in ${langName}. DO NOT skip this question.
4. After they answer about hours, call report_result (record their exact words)
5. End with a polite goodbye in ${langName}

Rules:
- ★ Speak VERY fast, at 2x normal speed. Be quick and efficient. No pauses.
- Be polite but extremely concise. No unnecessary preamble.
- Wait for the other person to finish speaking before responding.
- If open: do NOT call report_result before asking about hours. You MUST ask hours first.
- If closed or unclear: call report_result immediately.
- No response for 5 seconds: say "Hello?" in ${langName}. Still no response after 5 more seconds: report as no_response.
- Record times accurately. Record exactly what they said.
- hours_answer MUST contain the other person's exact words. Do NOT translate or modify.
- open_answer MUST contain their exact words. Do NOT translate or modify.
- All text in report_result should be in ${langName}, as spoken by the other person.`;
}

/* === 汎用: 予約用プロンプト（ja/ko以外の全言語） === */
function buildReservationInstructionsGeneric(lang, params) {
  const langName = getLangName(lang);
  const rsvDateRaw = params.rsv_date || 'unknown';
  const rsvTimeRaw = params.rsv_time || 'unknown';
  const rsvPartySize = params.rsv_party_size || 'unknown';
  const rsvName = params.rsv_name || 'unknown';
  const rsvPhone = params.rsv_phone || 'unknown';
  const isFlexible = params.rsv_flexible === '1';
  const flexBefore = parseInt(params.rsv_flex_before || '60', 10);
  const flexAfter = parseInt(params.rsv_flex_after || '60', 10);

  /* 日本語日付→英語変換、時刻→12時間AM/PM変換 */
  const rsvDate = formatDateFromJapanese(rsvDateRaw);
  const rsvTime = formatTime12h(rsvTimeRaw);

  let flexRangeDesc = '';
  if (isFlexible && params.rsv_time) {
    const tm = params.rsv_time.match(/^(\d{1,2}):(\d{2})$/);
    if (tm) {
      const baseMin = parseInt(tm[1], 10) * 60 + parseInt(tm[2], 10);
      flexRangeDesc = `${formatMinutes12h(baseMin - flexBefore)} to ${formatMinutes12h(baseMin + flexAfter)}`;
    }
  }

  const flexDesc = isFlexible
    ? `Flexible: YES (accept times between ${flexRangeDesc} only)`
    : 'Flexible: NO (if the exact requested time is unavailable, end the call without booking)';

  const flexStep = isFlexible
    ? `4. If the requested time is unavailable, ask: "Do you have availability between ${flexRangeDesc}?" in ${langName}.
   ★★★ STRICT: Only accept times within ${flexRangeDesc}. Reject anything outside this range.
   If nothing is available in range, politely decline and say goodbye. Report as rejected.`
    : `4. If the requested time is unavailable, politely say "I understand, I'll try again another time. Thank you." in ${langName} and end the call. Report as rejected. Do NOT ask for alternative times.`;

  return `You are an AI assistant calling a store to make a reservation by phone.
★★★ CRITICAL: You MUST speak ONLY in ${langName} throughout the ENTIRE call. NEVER use any other language. ★★★

Reservation details:
- Date: ${rsvDate}
- Time: ${rsvTime}
- Party size: ${rsvPartySize}
- Name: ${rsvName}
- Phone: ${rsvPhone}
- ${flexDesc}

★★★ TIME FORMAT RULES ★★★
- ALWAYS use 12-hour AM/PM format when speaking times (e.g., "8:30 PM", NOT "20:30").
- When the other person says a time like "10 o'clock" or "ten", determine AM/PM from context (evening reservation → PM).
- NEVER use 24-hour format (like "20:30" or "19:00") in conversation. Convert to 12-hour format.
- Examples: 20:30 → "eight thirty PM", 19:00 → "seven PM", 21:00 → "nine PM"

Conversation flow (★ At each step, say only ONE thing and wait for their response. NEVER say two pieces of info in the same utterance ★):
1. Greet in ${langName} and say "I'd like to make a reservation." Wait for their response. Do NOT give details yet.
2. When they respond, say: "I'd like a reservation for ${rsvPartySize} people on ${rsvDate} at ${rsvTime}. Is that available?" in ${langName}.
   [IMPORTANT] After asking this, do NOT call report_reservation_result. Wait for their answer.
3. ★ Listen to their full response ★ Do NOT judge the result immediately after your question.
   ★ If they mention a time (e.g., "10 o'clock"), understand it in context. For evening reservations, "10" means 10 PM.
${flexStep}
5. If reservation is OK:
   - Time unchanged → immediately say "The name is ${rsvName}" in ${langName}. Nothing else.
   - Time changed → confirm the new time first, wait for response, THEN give the name.
7. Wait for them to confirm the name.
8. After name confirmed, say "The phone number is ${rsvPhone}" - read it digit by digit clearly in ${langName}. ★★★ Read ONCE only. Do NOT repeat. ★★★
9. After giving the phone number, wait silently for their response.
10. When they confirm, say "Thank you" in ${langName}. ★ Do NOT say this in the same utterance as the phone number ★
11. After they give final confirmation, THEN and ONLY then call report_reservation_result with status "confirmed".
12. After calling report_reservation_result, say a polite goodbye in ${langName}.

Critical rules:
- ★ Speak VERY fast, at 2x normal speed. Efficient and polite. No pauses.
- Be polite but concise in ${langName}.
- NEVER interrupt. Wait for the other person to finish.
- ★★★ Say only ONE thing per turn. NEVER combine two pieces of information. ★★★
- ★★★ report_reservation_result rules:
  ■ FORBIDDEN: Do NOT call it in the same turn as asking a question.
  ■ FORBIDDEN: Do NOT guess the result before hearing their response.
  ■ rejected: ONLY when they explicitly decline.
  ■ confirmed: ONLY after name AND phone AND final confirmation received.
  ■ NEVER call it while you are still speaking.
- No response for 10 seconds: say "Hello?" in ${langName}. 10 more seconds: report as no_response.
- You MUST call report_reservation_result before the call ends.
- Record all results in ${langName}. Do NOT use other languages.`;
}

function elapsed(start) {
  return `${(Date.now() - start)}ms`;
}

async function sendResultToHeteml(callSid, result, conversationLog, targetUrl) {
  const url = targetUrl || CALLBACK_URL;
  const t = Date.now();
  try {
    const payload = { secret: CALLBACK_SECRET, call_sid: callSid, result, conversation_log: conversationLog };
    console.log(`[POST] CallSid=${callSid} -> ${url}`);
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const text = await response.text();
    console.log(`[RES] ${response.status} ${text} (${elapsed(t)})`);
  } catch (err) {
    console.error('[ERR] callback:', err.message);
  }
}

const app = Fastify({ logger: false });
await app.register(fastifyWs);
await app.register(fastifyFormBody);

app.get('/', async () => ({
  status: 'ok',
  service: 'callcheck-turbo-relay',
  voice: OPENAI_VOICE,
  model: OPENAI_MODEL
}));

app.all('/twiml', async (req, reply) => {
  const callSid = req.body?.CallSid || req.query?.CallSid || 'unknown';
  const wsHost = req.hostname;
  reply.type('text/xml').send(`<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Connect>
    <Stream url="wss://${wsHost}/media-stream">
      <Parameter name="CallSid" value="${callSid}"/>
    </Stream>
  </Connect>
</Response>`);
});

app.register(async function (fastify) {
  fastify.get('/media-stream', { websocket: true }, (socket, req) => {
    console.log('[WS] Twilio connected');
    let streamSid = null, callSid = null, openaiWs = null;
    let conversationLog = [];
    let connectTime = Date.now();
    let firstAudioSent = false;
    let lastSpeechEnd = 0;
    let farewellSent = false;
    let hasActiveResponse = false;
    let aiSpeaking = false;
    let callMode = 'check';
    let callLang = 'ja';
    let rsvParams = {};
    let resultSent = false;
    let callbackUrl = CALLBACK_URL;
    let greetingDone = false;  /* 予約モード: 最初の挨拶が完了したか */
    let lastAudioDoneTime = 0; /* AI音声送信完了時刻（Twilioバッファ保護用） */
    let currentResponseAudioBytes = 0;  /* 現在のレスポンスの音声データ量 */
    let responseAudioStartTime = 0;     /* 現在のレスポンスの最初の音声デルタ時刻 */
    let estimatedPlaybackEndTime = 0;   /* Twilio再生完了推定時刻（エコーゲート用） */
    let farewellCloseScheduled = false; /* farewell切断タイマーが設定済みか */

    function connectOpenAI() {
      const t = Date.now();
      const isReservation = callMode === 'reservation';
      const model = isReservation ? OPENAI_MODEL_FULL : OPENAI_MODEL;
      const url = `wss://api.openai.com/v1/realtime?model=${model}`;
      openaiWs = new WebSocket(url, {
        headers: { 'Authorization': `Bearer ${OPENAI_API_KEY}`, 'OpenAI-Beta': 'realtime=v1' }
      });

      /* OpenAI接続タイムアウト: 10秒以内に接続できなければ切断 */
      const connectTimeout = setTimeout(() => {
        if (openaiWs?.readyState !== WebSocket.OPEN) {
          console.error('[OpenAI] Connection timeout (10s)');
          openaiWs?.close();
          if (socket.readyState === 1) socket.close();
        }
      }, 10000);

      openaiWs.on('open', () => {
        clearTimeout(connectTimeout);
        console.log(`[OpenAI] Connected (${elapsed(t)}) mode=${callMode} lang=${callLang} model=${model}`);

        let sessionInstructions;
        if (isReservation) {
          if (callLang === 'ja') sessionInstructions = buildReservationInstructions(rsvParams);
          else if (callLang === 'ko') sessionInstructions = buildReservationInstructionsKo(rsvParams);
          else sessionInstructions = buildReservationInstructionsGeneric(callLang, rsvParams);
        } else {
          if (callLang === 'ja') sessionInstructions = SYSTEM_INSTRUCTIONS;
          else if (callLang === 'ko') sessionInstructions = SYSTEM_INSTRUCTIONS_KO;
          else sessionInstructions = buildCheckInstructionsGeneric(callLang);
        }
        const sessionTools = isReservation ? RESERVATION_TOOLS : TOOLS;

        /* VAD設定: エコーゲート改善済みのため1500msに短縮 */
        const vadConfig = isReservation ? {
          type: 'server_vad',
          threshold: 0.6,
          prefix_padding_ms: 300,
          silence_duration_ms: 1500,
        } : {
          type: 'server_vad',
          threshold: 0.5,
          prefix_padding_ms: 250,
          silence_duration_ms: 800,
        };

        /* セッション設定を送信 */
        const sessionConfig = {
          modalities: ['text', 'audio'],
          instructions: sessionInstructions,
          voice: OPENAI_VOICE,
          input_audio_format: 'g711_ulaw',
          output_audio_format: 'g711_ulaw',
          input_audio_transcription: isReservation ? { model: 'whisper-1' } : null,
          turn_detection: isReservation ? null : vadConfig,
          tools: sessionTools,
          tool_choice: 'auto',
          temperature: isReservation ? 0.6 : 0.6,
          max_response_output_tokens: isReservation ? 600 : 256
        };
        openaiWs.send(JSON.stringify({ type: 'session.update', session: sessionConfig }));

        /* 挨拶送信: session.updatedを待つか、最大1.5秒後にフォールバック */
        let greetingSent = false;
        const sendGreeting = () => {
          if (greetingSent || openaiWs?.readyState !== WebSocket.OPEN) return;
          greetingSent = true;
          let instr;
          if (callLang === 'ja') {
            instr = isReservation
              ? '次のセリフだけ話せ: あの、すみません、予約をお願いしたいのですが。 ― 一字一句このまま話せ。省略するな。追加するな。'
              : '極めて早口で「あの、すみません。今営業されてますか？」とだけ言え。余計な前置き不要。間を空けるな。一切の遅延なく話し始めろ。';
          } else if (callLang === 'ko') {
            instr = isReservation
              ? '다음 대사만 말해라: 안녕하세요, 예약을 하고 싶은데요. ― 한 글자도 빠짐없이 그대로 말해라. 생략하지 마라. 추가하지 마라.'
              : '매우 빠르게 "안녕하세요, 지금 영업 중이신가요?"라고만 말해라. 불필요한 서두 없이. 즉시 말하기 시작해라.';
          } else {
            const ln = getLangName(callLang);
            instr = isReservation
              ? `Speak ONLY in ${ln}. Say this very quickly and naturally: Greet politely and say "I'd like to make a reservation." Nothing else. Do not give any details yet.`
              : `Speak ONLY in ${ln}. Say this very quickly and naturally: Greet politely and ask "Are you open right now?" Nothing else. No preamble. Start speaking immediately.`;
          }
          console.log(`[GREETING] Sending greeting (mode=${callMode})`);
          openaiWs.send(JSON.stringify({
            type: 'response.create',
            response: { modalities: ['text', 'audio'], instructions: instr }
          }));
        };

        /* session.updatedイベントで挨拶を送信（最速パス） */
        const onSessionUpdated = (data) => {
          try {
            const ev = JSON.parse(data.toString());
            if (ev.type === 'session.updated') {
              openaiWs.removeListener('message', onSessionUpdated);
              clearTimeout(greetingFallback);
              /* stream開始=相手が電話に出た後なので遅延不要。即座に挨拶 */
              sendGreeting();
            }
          } catch(e) {}
        };
        openaiWs.on('message', onSessionUpdated);

        /* フォールバック: session.updatedが来ない場合のタイムアウト */
        const greetingFallback = setTimeout(() => {
          openaiWs.removeListener('message', onSessionUpdated);
          console.warn('[GREETING] session.updated not received, sending greeting via fallback');
          sendGreeting();
        }, isReservation ? 1500 : 1000);
      });

      openaiWs.on('message', (data) => {
        try { handleOpenAIEvent(JSON.parse(data.toString())); } catch(e) { console.error('[OpenAI] Message parse error:', e.message); }
      });
      openaiWs.on('error', (err) => console.error('[OpenAI] Error:', err.message));
      openaiWs.on('close', (code) => {
        console.log(`[OpenAI] Closed: ${code}`);
        if (socket.readyState === 1) {
          console.log('[OpenAI] Closing Twilio socket after OpenAI disconnect');
          socket.close();
        }
      });
    }

    let greetingAudioSent = false; /* 挨拶で音声が送信されたか */
    let greetingRetryCount = 0;
    let responseHangTimer = null; /* レスポンスハング検出タイマー */

    function handleOpenAIEvent(event) {
      switch (event.type) {
        case 'error':
          console.error('[OpenAI] ERROR:', JSON.stringify(event.error || event));
          break;

        case 'session.created':
          console.log('[OpenAI] Session created');
          break;

        case 'session.updated':
          console.log('[OpenAI] Session updated');
          break;

        case 'response.audio.delta':
          if (event.delta && streamSid) {
            aiSpeaking = true;
            if (!responseAudioStartTime) responseAudioStartTime = Date.now();
            currentResponseAudioBytes += event.delta.length * 3 / 4; // base64→raw bytes
            if (!greetingDone) greetingAudioSent = true;
            if (!firstAudioSent) {
              firstAudioSent = true;
              /* 最初の音声前に300ms無音パッド（Twilio再生開始の安定化） */
              const silenceBuf = Buffer.alloc(2400, 0xFF); // 300ms @ 8kHz g711_ulaw
              socket.send(JSON.stringify({ event: 'media', streamSid, media: { payload: silenceBuf.toString('base64') } }));
              console.log(`[TIMING] First audio delta: ${elapsed(connectTime)} from WS connect (+300ms pad)`);
            }
            socket.send(JSON.stringify({ event: 'media', streamSid, media: { payload: event.delta } }));
          }
          break;

        case 'response.audio.done':
          aiSpeaking = false;
          lastAudioDoneTime = Date.now();
          /* Twilio再生完了推定時刻を計算（エコーゲートに使用） */
          if (currentResponseAudioBytes > 0) {
            const playbackMs = (currentResponseAudioBytes / 8000) * 1000;
            const streamingElapsed = responseAudioStartTime > 0 ? (Date.now() - responseAudioStartTime) : 0;
            const remainingPlayback = Math.max(0, playbackMs - streamingElapsed);
            const newEndTime = Date.now() + remainingPlayback + 300;
            estimatedPlaybackEndTime = Math.max(estimatedPlaybackEndTime, newEndTime);
            console.log(`[ECHO] Audio done (${Math.round(playbackMs)}ms audio, ~${Math.round(remainingPlayback)}ms remaining on Twilio)`);
            /* バッファクリアでエコーを除去 */
            if (openaiWs?.readyState === WebSocket.OPEN && !farewellSent) {
              openaiWs.send(JSON.stringify({ type: 'input_audio_buffer.clear' }));
            }
          }
          currentResponseAudioBytes = 0;
          responseAudioStartTime = 0;
          /* 電話番号配信後: エコーゲート終了+2秒でspeechがなければ自動「よろしくお願いします」 */
          if (callMode === 'reservation' && phoneDelivered && humanSpeechAfterPhone === 0 && !farewellSent) {
            if (phoneFollowUpTimer) clearTimeout(phoneFollowUpTimer);
            const waitMs = estimatedPlaybackEndTime > 0
              ? Math.max(0, estimatedPlaybackEndTime - Date.now()) + 2000
              : 3000;
            phoneFollowUpTimer = setTimeout(() => {
              if (humanSpeechAfterPhone === 0 && openaiWs?.readyState === WebSocket.OPEN && !farewellSent) {
                let phoneFollowInstr;
                if (callLang === 'ja') phoneFollowInstr = '「よろしくお願いします」とだけ言え。それ以外何も言うな。';
                else if (callLang === 'ko') phoneFollowInstr = '"잘 부탁드립니다"라고만 말해라. 그 외에는 아무것도 말하지 마라.';
                else phoneFollowInstr = `Speak ONLY in ${getLangName(callLang)}. Say "Thank you, I appreciate it" in ${getLangName(callLang)}. Nothing else.`;
                console.log(`[PROACTIVE] No speech after phone number, sending follow-up (lang=${callLang})`);
                openaiWs.send(JSON.stringify({
                  type: 'response.create',
                  response: { modalities: ['text', 'audio'], instructions: phoneFollowInstr }
                }));
              }
            }, waitMs);
            console.log(`[PHONE] Follow-up timer set: ${Math.round(waitMs/1000)}s`);
          }
          break;

        case 'response.audio_transcript.done':
          if (event.transcript) {
            console.log(`[AI] ${event.transcript}`);
            conversationLog.push({ role: 'ai', text: event.transcript, time: Date.now() });
            /* 予約モード: 名前・電話番号の発話を検出 */
            if (callMode === 'reservation') {
              const t = event.transcript;
              if (rsvParams.rsv_name && t.includes(rsvParams.rsv_name)) {
                nameDelivered = true;
                console.log(`[STEP] Name delivered: ${rsvParams.rsv_name}`);
              }
              if (rsvParams.rsv_phone && !phoneDelivered) {
                const isPhoneMention =
                  t.includes('ゼロ') || t.includes('電話番号') ||      // Japanese
                  t.includes('공') || t.includes('전화번호') ||        // Korean
                  /phone|number|numer|номер|号码|號碼|번호|numéro|número|telefon|Nummer|หมายเลข|điện thoại|nomor|nombor|رقم/i.test(t);
                if (isPhoneMention) {
                  phoneDelivered = true;
                  console.log(`[STEP] Phone delivered`);
                }
              }
            }
          }
          break;

        case 'conversation.item.input_audio_transcription.completed':
          if (event.transcript) {
            console.log(`[Human] ${event.transcript}`);
            conversationLog.push({ role: 'human', text: event.transcript, time: Date.now() });
          }
          break;

        case 'input_audio_buffer.speech_stopped':
          lastSpeechEnd = Date.now();
          console.log(`[TIMING] Speech stopped detected`);
          if (phoneDelivered) humanSpeechAfterPhone++;
          /* 予約モード: 電話番号配信後+相手が応答した後のspeech_stopped →
             次のレスポンスはfunction call(report_reservation_result)の可能性が高い。
             残留オーディオがturn_detectedを引き起こしてfunction callを切断するのを防ぐ */
          if (callMode === 'reservation' && phoneDelivered && humanSpeechAfterPhone >= 1) {
            if (openaiWs?.readyState === WebSocket.OPEN) {
              openaiWs.send(JSON.stringify({ type: 'input_audio_buffer.clear' }));
              console.log('[ECHO] Pre-function-call buffer clear');
            }
          }
          break;

        case 'response.created':
          hasActiveResponse = true;
          if (lastSpeechEnd > 0) {
            console.log(`[TIMING] Response created: ${elapsed(lastSpeechEnd)} after speech stopped`);
          }
          /* レスポンスハング検出: 10秒以内にresponse.doneが来なければキャンセル→リトライ */
          if (responseHangTimer) clearTimeout(responseHangTimer);
          responseHangTimer = setTimeout(() => {
            if (hasActiveResponse && openaiWs?.readyState === WebSocket.OPEN) {
              console.warn('[HANG] Response timeout (10s), cancelling and retrying');
              openaiWs.send(JSON.stringify({ type: 'response.cancel' }));
              /* Twilioの出力バッファもクリア（溜まった長い音声を止める） */
              if (streamSid && socket.readyState === 1) {
                socket.send(JSON.stringify({ event: 'clear', streamSid }));
                console.log('[HANG] Twilio output buffer cleared');
              }
              estimatedPlaybackEndTime = 0; /* エコーゲートもリセット */
              setTimeout(() => {
                if (openaiWs?.readyState === WebSocket.OPEN && !farewellSent) {
                  openaiWs.send(JSON.stringify({
                    type: 'response.create',
                    response: { modalities: ['text', 'audio'] }
                  }));
                }
              }, 300);
            }
          }, 10000);
          break;

        case 'response.done': {
          hasActiveResponse = false;
          if (responseHangTimer) { clearTimeout(responseHangTimer); responseHangTimer = null; }
          const respStatus = event.response?.status || 'unknown';
          const respOutput = event.response?.output?.length || 0;
          /* キャンセルされた場合: バッファクリアで残留エコーを除去 */
          if (respStatus === 'cancelled') {
            aiSpeaking = false;
            lastAudioDoneTime = Date.now();
            const cancelReason = event.response?.status_details?.reason || '';
            /* client_cancelled = こちらからキャンセル（hangタイマー等）→ Twilio clearも送信済み
               turn_detected = OpenAI VADによるキャンセル → Twilioはまだ再生中の可能性 */
            if (cancelReason !== 'client_cancelled' && currentResponseAudioBytes > 0) {
              const playbackMs = (currentResponseAudioBytes / 8000) * 1000;
              const streamingElapsed = responseAudioStartTime > 0 ? (Date.now() - responseAudioStartTime) : 0;
              const remainingPlayback = Math.max(0, playbackMs - streamingElapsed);
              const newEndTime = Date.now() + remainingPlayback + 300;
              estimatedPlaybackEndTime = Math.max(estimatedPlaybackEndTime, newEndTime);
            }
            if (openaiWs?.readyState === WebSocket.OPEN) {
              openaiWs.send(JSON.stringify({ type: 'input_audio_buffer.clear' }));
              console.log(`[ECHO] Cancelled (${cancelReason}): buffer cleared, echoGate=${estimatedPlaybackEndTime > 0 ? (estimatedPlaybackEndTime - Date.now()) + 'ms' : 'none'}`);
            }
            currentResponseAudioBytes = 0;
            responseAudioStartTime = 0;
          }
          if (respStatus !== 'completed') {
            console.error(`[OpenAI] Response status: ${respStatus}`, JSON.stringify(event.response?.status_details || {}));
          }

          /* 予約モード: 挨拶完了チェック */
          if (callMode === 'reservation' && !greetingDone && !farewellSent) {
            if (!greetingAudioSent && greetingRetryCount < 2) {
              /* 音声が生成されなかった → リトライ */
              greetingRetryCount++;
              console.warn(`[RETRY] Greeting produced no audio (status=${respStatus}), retry #${greetingRetryCount}`);
              setTimeout(() => {
                if (openaiWs?.readyState !== WebSocket.OPEN) return;
                let retryInstr;
                if (callLang === 'ja') retryInstr = '次のセリフだけ話せ: あの、すみません、予約をお願いしたいのですが。 ― 一字一句このまま話せ。省略するな。追加するな。';
                else if (callLang === 'ko') retryInstr = '다음 대사만 말해라: 안녕하세요, 예약을 하고 싶은데요. ― 한 글자도 빠짐없이 그대로 말해라. 생략하지 마라. 추가하지 마라.';
                else retryInstr = `Speak ONLY in ${getLangName(callLang)}. Greet politely and say "I'd like to make a reservation." Nothing else.`;
                openaiWs.send(JSON.stringify({
                  type: 'response.create',
                  response: {
                    modalities: ['text', 'audio'],
                    instructions: retryInstr
                  }
                }));
              }, 500);
              break;
            }
            greetingDone = true;
            console.log('[VAD] Greeting done, clearing buffer + enabling server_vad');
            openaiWs.send(JSON.stringify({ type: 'input_audio_buffer.clear' }));
            openaiWs.send(JSON.stringify({
              type: 'session.update',
              session: { turn_detection: { type: 'server_vad', threshold: 0.6, prefix_padding_ms: 300, silence_duration_ms: 1500 } }
            }));
            /* 挨拶後のプロアクティブ: エコーゲート終了+3秒でspeechがなければ自動で会話を促す */
            {
              const waitMs = estimatedPlaybackEndTime > 0
                ? Math.max(0, estimatedPlaybackEndTime - Date.now()) + 3000
                : 4000;
              greetingFollowUpTimer = setTimeout(() => {
                if (greetingDone && openaiWs?.readyState === WebSocket.OPEN && !farewellSent && !hasActiveResponse) {
                  console.log('[PROACTIVE] No speech after greeting, prompting AI');
                  const followUpText = callLang === 'ja' ? 'はい、ご予約ですか？'
                    : callLang === 'ko' ? '네, 예약이요?'
                    : 'Yes, a reservation?';
                  openaiWs.send(JSON.stringify({
                    type: 'conversation.item.create',
                    item: { type: 'message', role: 'user', content: [{ type: 'input_text', text: followUpText }] }
                  }));
                  openaiWs.send(JSON.stringify({
                    type: 'response.create',
                    response: { modalities: ['text', 'audio'] }
                  }));
                }
              }, waitMs);
              console.log(`[GREETING] Follow-up timer set: ${Math.round(waitMs/1000)}s`);
            }
          }
          if (farewellSent && !farewellCloseScheduled) {
            /* farewell音声のresponse.doneまで待ってからタイマー設定
               function callの応答は type='function_call'、farewell音声は type='message' */
            if (respStatus === 'completed' && event.response?.output?.some(o => o.type === 'message')) {
              farewellCloseScheduled = true;
              const closeDelay = callMode === 'reservation' ? 6000 : 4000;
              console.log(`[AUTO] Farewell audio done, closing in ${closeDelay/1000}s (total: ${elapsed(connectTime)})`);
              setTimeout(() => {
                if (openaiWs?.readyState === WebSocket.OPEN) openaiWs.close();
                if (socket.readyState === 1) socket.close();
              }, closeDelay);
            }
          }
          break;
        }

        case 'response.function_call_arguments.done':
          handleFunctionCall(event);
          break;

        case 'input_audio_buffer.speech_started':
          /* AI発話中はエコー割り込みを無視 */
          if (aiSpeaking) {
            console.log(`[VAD] speech_started ignored (aiSpeaking=true)`);
            break;
          }
          /* お礼送信後は全モードで無視（farewell後のループ防止） */
          if (farewellSent) {
            console.log(`[VAD] speech_started ignored (farewell=true)`);
            break;
          }
          /* AI音声がTwilioで再生中の可能性がある間はエコーとして無視 */
          if (estimatedPlaybackEndTime > 0 && Date.now() < estimatedPlaybackEndTime) {
            console.log(`[VAD] speech_started: skip (echo gate, ${estimatedPlaybackEndTime - Date.now()}ms left)`);
            break;
          }
          /* フォローアップタイマーをキャンセル（相手が喋った） */
          if (phoneFollowUpTimer) { clearTimeout(phoneFollowUpTimer); phoneFollowUpTimer = null; }
          if (greetingFollowUpTimer) { clearTimeout(greetingFollowUpTimer); greetingFollowUpTimer = null; }
          if (callMode !== 'reservation') {
            if (streamSid) socket.send(JSON.stringify({ event: 'clear', streamSid }));
            if (hasActiveResponse && openaiWs?.readyState === WebSocket.OPEN) {
              openaiWs.send(JSON.stringify({ type: 'response.cancel' }));
            }
          } else {
            console.log(`[VAD] speech_started: reservation mode, skip Twilio clear & response.cancel`);
          }
          break;

        case 'error':
          if (event.error?.code === 'response_cancel_not_active') break;
          console.error('[OpenAI] Error:', JSON.stringify(event.error));
          break;
      }
    }

    let nameDelivered = false;   /* 名前を伝えたか */
    let phoneDelivered = false;  /* 電話番号を伝えたか */
    let humanSpeechAfterPhone = 0; /* 電話番号後の相手発話回数 */
    let phoneFollowUpTimer = null; /* 電話番号後の自動フォローアップタイマー */
    let greetingFollowUpTimer = null; /* 挨拶後の自動フォローアップタイマー */

    function handleFunctionCall(event) {
      const { name, call_id, arguments: argsStr } = event;
      if (name === 'report_result' || name === 'report_reservation_result') {
        let args;
        try {
          args = JSON.parse(argsStr);
        } catch(e) {
          console.error(`[FnCall] JSON parse error: ${e.message}, raw: ${argsStr}`);
          /* 切断されたJSONの修復を試行 */
          args = null;
          try {
            /* 方法1: 未閉じの文字列を閉じ、末尾の不完全なキー・値を除去 */
            let repaired = argsStr.trim();
            /* 末尾の不完全なkey-value pair を除去 */
            repaired = repaired.replace(/,\s*"[^"]*"?\s*:?\s*"?[^"]*$/, '');
            if (!repaired.endsWith('}')) repaired += '}';
            args = JSON.parse(repaired);
            console.log(`[FnCall] JSON repaired (method 1): ${JSON.stringify(args)}`);
          } catch(e2) {
            try {
              /* 方法2: 未閉じの文字列値を閉じてからオブジェクトを閉じる */
              let repaired = argsStr.trim();
              const quoteCount = (repaired.match(/"/g) || []).length;
              if (quoteCount % 2 !== 0) repaired += '"';
              if (!repaired.endsWith('}')) repaired += '}';
              args = JSON.parse(repaired);
              console.log(`[FnCall] JSON repaired (method 2): ${JSON.stringify(args)}`);
            } catch(e3) {
              /* 方法3: 正規表現で全フィールドを抽出 */
              args = {};
              const fieldPattern = /"(\w+)"\s*:\s*"([^"]*)/g;
              let match;
              while ((match = fieldPattern.exec(argsStr)) !== null) {
                args[match[1]] = match[2];
              }
              console.log(`[FnCall] JSON repaired (regex fallback): ${JSON.stringify(args)}`);
            }
          }
          /* 最低限のフィールドが無い場合のフォールバック */
          if (name === 'report_reservation_result' && !args.reservation_status) {
            args.reservation_status = 'confirmed';
            args.summary = '予約結果（JSON解析エラーのため詳細不明）';
          }
          if (name === 'report_result' && !args.open_status) {
            args.open_status = 'unknown';
            args.open_answer = 'JSON解析エラー';
          }
        }

        /* ★ 予約モード: confirmed/unknownの場合、手順を踏んでいなければ拒否 */
        if (name === 'report_reservation_result'
            && args.reservation_status !== 'rejected'
            && args.reservation_status !== 'no_response') {
          let blocked = false;
          let reason = '';
          if (!nameDelivered) {
            blocked = true;
            if (callLang === 'ja') reason = `まだ予約者の名前を相手に伝えていない。「名前は${rsvParams.rsv_name}です」と相手に伝えろ。相手に名前を聞くな。こちらから名乗れ。`;
            else if (callLang === 'ko') reason = `아직 예약자 이름을 상대방에게 전달하지 않았다. "이름은 ${rsvParams.rsv_name}입니다"라고 상대방에게 전달해라.`;
            else reason = `You have not told them the name yet. Tell them "The name is ${rsvParams.rsv_name}" in ${getLangName(callLang)}. Do not ask for their name. Give YOUR name.`;
          } else if (!phoneDelivered) {
            blocked = true;
            if (callLang === 'ja') reason = 'まだ電話番号を相手に伝えていない。手順通りに電話番号を読み上げろ。';
            else if (callLang === 'ko') reason = '아직 전화번호를 상대방에게 전달하지 않았다. 절차대로 전화번호를 읽어라.';
            else reason = 'You have not given them the phone number yet. Read the phone number digit by digit as instructed.';
          } else if (humanSpeechAfterPhone < 1) {
            blocked = true;
            if (callLang === 'ja') reason = '電話番号を伝えた後、相手の最終確認をまだ受けていない。';
            else if (callLang === 'ko') reason = '전화번호를 전달한 후, 상대방의 최종 확인을 아직 받지 못했다.';
            else reason = 'You have not received final confirmation after giving the phone number. Wait for their response.';
          }
          if (blocked) {
            console.warn(`[GUARD] report_reservation_result BLOCKED (name=${nameDelivered}, phone=${phoneDelivered}, humanAfterPhone=${humanSpeechAfterPhone}) reason: ${reason}`);
            if (openaiWs?.readyState === WebSocket.OPEN) {
              openaiWs.send(JSON.stringify({
                type: 'conversation.item.create',
                item: { type: 'function_call_output', call_id, output: JSON.stringify({success: false, error: reason}) }
              }));
              /* humanAfterPhone待ちの場合: 既に「よろしくお願いします」と言っている可能性が高いので
                 何も言わずに相手の返事を待つ。response.createを送らない（server_vadが次のターンを処理） */
              if (humanSpeechAfterPhone < 1) {
                console.log('[GUARD] Waiting for human response, no retry response sent');
              } else {
                let guardSuffix;
                if (callLang === 'ja') guardSuffix = ' report_reservation_resultはまだ呼ぶな。';
                else if (callLang === 'ko') guardSuffix = ' report_reservation_result는 아직 호출하지 마라.';
                else guardSuffix = ' Do NOT call report_reservation_result yet.';
                openaiWs.send(JSON.stringify({
                  type: 'response.create',
                  response: { instructions: reason + guardSuffix }
                }));
              }
            }
            return;
          }
        }

        console.log(`[${name}]`, JSON.stringify(args));
        resultSent = true;
        if (callSid) sendResultToHeteml(callSid, args, conversationLog, callbackUrl);
        if (openaiWs?.readyState === WebSocket.OPEN) {
          openaiWs.send(JSON.stringify({
            type: 'conversation.item.create',
            item: { type: 'function_call_output', call_id, output: '{"success":true}' }
          }));
          farewellSent = true;
          /* farewell中のturn_detected防止: VADを無効化して
             エコーがAI音声をキャンセルしないようにする */
          openaiWs.send(JSON.stringify({
            type: 'session.update',
            session: { turn_detection: null }
          }));
          let farewellInstr;
          if (callLang === 'ja') farewellInstr = '早口で次のセリフだけ言え:「ありがとうございました。しつれいいたします」。一字一句このまま。それ以外何も言うな。';
          else if (callLang === 'ko') farewellInstr = '빠르게 다음 대사만 말해라: "감사합니다. 수고하세요". 한 글자도 빠짐없이 그대로. 그 외에는 아무것도 말하지 마라.';
          else farewellInstr = `Speak ONLY in ${getLangName(callLang)}. Say a quick, polite goodbye (like "Thank you, goodbye.") in ${getLangName(callLang)}. Nothing else.`;
          openaiWs.send(JSON.stringify({
            type: 'response.create',
            response: {
              instructions: farewellInstr
            }
          }));
        }
      }
    }

    socket.on('message', (message) => {
      try {
        const msg = JSON.parse(message.toString());
        switch (msg.event) {
          case 'start':
            streamSid = msg.start.streamSid;
            callSid = msg.start.customParameters?.CallSid || msg.start.callSid;
            callMode = msg.start.customParameters?.mode || 'check';
            callLang = msg.start.customParameters?.lang || 'ja';
            const cp = msg.start.customParameters || {};
            // 呼び出し元から渡されたcallback_urlを優先、なければ環境変数
            callbackUrl = cp.callback_url || CALLBACK_URL;
            rsvParams = {
              rsv_date: cp.rsv_date || '',
              rsv_time: cp.rsv_time || '',
              rsv_party_size: cp.rsv_party_size || '',
              rsv_name: cp.rsv_name || '',
              rsv_phone: cp.rsv_phone || '',
              rsv_flexible: cp.rsv_flexible || '0',
              rsv_flex_before: cp.rsv_flex_before || '60',
              rsv_flex_after: cp.rsv_flex_after || '60'
            };
            connectTime = Date.now();
            console.log(`[Stream] Start: ${callSid} mode=${callMode} lang=${callLang}`, callMode === 'reservation' ? rsvParams : '');
            connectOpenAI();
            break;
          case 'media':
            if (openaiWs?.readyState === WebSocket.OPEN) {
              /* エコーゲート: AI発話中＋Twilio再生完了推定時刻まで音声を転送しない */
              if (aiSpeaking || (estimatedPlaybackEndTime > 0 && Date.now() < estimatedPlaybackEndTime)) {
                break;
              }
              openaiWs.send(JSON.stringify({ type: 'input_audio_buffer.append', audio: msg.media.payload }));
            }
            break;
          case 'stop':
            console.log(`[Stream] Stop (total: ${elapsed(connectTime)})`);
            if (openaiWs?.readyState === WebSocket.OPEN) openaiWs.close();
            break;
        }
      } catch(e) { console.error('[Twilio] Message parse error:', e.message); }
    });

    socket.on('error', (err) => {
      console.error('[Twilio] Socket error:', err.message);
    });

    socket.on('close', () => {
      console.log('[WS] Twilio disconnected');
      if (openaiWs?.readyState === WebSocket.OPEN) openaiWs.close();
      /* 予約モードで結果が送信されずに切断された場合、unknownとして報告 */
      if (callMode === 'reservation' && !resultSent && callSid) {
        let safetyMsg;
        if (callLang === 'ja') safetyMsg = '通話が終了しましたが、予約結果を確認できませんでした。';
        else if (callLang === 'ko') safetyMsg = '통화가 종료되었지만, 예약 결과를 확인할 수 없었습니다.';
        else safetyMsg = 'The call ended but the reservation result could not be confirmed.';
        console.log('[SAFETY] Reservation call ended without result, sending unknown');
        sendResultToHeteml(callSid, {
          reservation_status: 'unknown',
          summary: safetyMsg,
        }, conversationLog, callbackUrl);
      }
    });
  });
});

try {
  await app.listen({ port: PORT, host: '0.0.0.0' });
  console.log(`
====================================
  callcheck TURBO relay on port ${PORT}
  model: ${OPENAI_MODEL}
  voice: ${OPENAI_VOICE}
  callback: ${CALLBACK_URL}
====================================`);
} catch(err) {
  console.error(err);
  process.exit(1);
}
