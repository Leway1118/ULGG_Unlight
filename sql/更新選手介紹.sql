SET @report_id := (
  SELECT id
  FROM tournament_reports
  WHERE tournament_id = 1
    AND issue_key = 'pre'
  LIMIT 1
);

UPDATE tournament_report_sections
SET block_json = JSON_SET(
  block_json,
  REPLACE(
    JSON_UNQUOTE(JSON_SEARCH(block_json, 'one', 'Creeper', NULL, '$[*].name')),
    '.name',
    '.tag'
  ),
  '無法選中之人',
  REPLACE(
    JSON_UNQUOTE(JSON_SEARCH(block_json, 'one', 'Creeper', NULL, '$[*].name')),
    '.name',
    '.intro'
  ),
  '出戰數為0，深怕踏入74C這個圈子會勸退其他所有人，所以選擇成為一名旁觀者。在你面前的是UL唯一真老師、壓力城最嚴厲的父親、一個人能抵千軍萬馬、劍1即可發動渦騎戰閃，左手直傷右手高防的人、披風切換者、三角定律案例者，壓力城對戰的終極戰士、苦力帕是也。'
)
WHERE report_id = @report_id
  AND section_key = 'player_reports'
  AND JSON_SEARCH(block_json, 'one', 'Creeper', NULL, '$[*].name') IS NOT NULL;