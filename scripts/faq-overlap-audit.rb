# Operational, read-only report. No FAQ text, embeddings or customer details in its output.
require 'json'

account = Account.find(Integer(ENV.fetch('CW_ACCOUNT_ID')))
assistant = account.captain_assistants.find(Integer(ENV.fetch('CW_ASSISTANT_ID')))
scan_all = ARGV.delete('--all')
abort 'Unexpected arguments' unless ARGV.empty?
after_id = Integer(ENV.fetch('FAQ_AFTER_ID', '0'))
batch = Integer(ENV.fetch('FAQ_BATCH_SIZE', '64'))
abort 'FAQ_BATCH_SIZE must be between 1 and 128' unless (1..128).cover?(batch)
abort 'FAQ_AFTER_ID must be nonnegative' if after_id.negative?

connection = ApplicationRecord.connection
upper_id = Captain::AssistantResponse.where(account_id: account.id, assistant_id: assistant.id).maximum(:id) || 0
clauses = { account: account.id, assistant: assistant.id, after_id: after_id, upper_id: upper_id, batch: batch }
query = lambda do |sql|
  connection.select_all(ApplicationRecord.sanitize_sql_array([sql, clauses])).to_a
end

exact = []
pairs = {}
inspected = 0
last_id = nil
loop do
  ids = ApplicationRecord.transaction do
    connection.execute('SET TRANSACTION READ ONLY')
    connection.execute("SET LOCAL statement_timeout = '8s'")
    connection.execute('SET LOCAL ivfflat.probes = 4')
    # These digests identify candidates only. Keep original text and all provenance until a reviewer decides.
    exact = query.call(<<~'SQL') if clauses[:after_id].zero?
      WITH fingerprints AS (
        SELECT id, md5(trim(regexp_replace(question,'\s+',' ','g'))) AS q,
               md5(trim(regexp_replace(answer,'\s+',' ','g'))) AS a
        FROM captain_assistant_responses
        WHERE account_id = :account AND assistant_id = :assistant AND status = 1
      )
      SELECT array_to_string(array_agg(id ORDER BY id), ',') AS ids, count(DISTINCT a) AS answer_versions
      FROM fingerprints GROUP BY q HAVING count(*) > 1 ORDER BY min(id)
    SQL
    # Use the existing pgvector L2 index to shortlist neighbours. Report actual cosine similarity;
    # a high score can also mean two answers disagree about the same question.
    neighbours = query.call(<<~'SQL')
      WITH sample AS MATERIALIZED (
        SELECT id, embedding FROM captain_assistant_responses
        WHERE account_id = :account AND assistant_id = :assistant AND status = 1 AND id > :after_id AND id <= :upper_id
          AND embedding IS NOT NULL ORDER BY id LIMIT :batch
      )
      SELECT s.id, near.id AS other_id, 1 - (s.embedding <=> near.embedding) AS similarity
      FROM sample s LEFT JOIN LATERAL (
        SELECT r.id, r.embedding FROM captain_assistant_responses r
        WHERE r.account_id = :account AND r.assistant_id = :assistant AND r.status = 1
          AND r.embedding IS NOT NULL AND r.id <> s.id
        ORDER BY r.embedding <-> s.embedding LIMIT 3
      ) near ON true ORDER BY s.id
    SQL
    neighbours.each do |row|
      next unless row['other_id'] && row['similarity'].to_f >= 0.90

      pair = [row.fetch('id'), row.fetch('other_id')].sort
      pairs[pair] = { faq_ids: pair, cosine: row.fetch('similarity').to_f.round(4) }
    end
    neighbours.pluck('id').uniq
  end

  inspected += ids.length
  last_id = ids.last if ids.any?
  break unless scan_all && ids.length == batch && last_id < upper_id

  clauses[:after_id] = last_id
  sleep 0.8
end
puts JSON.generate({ assistant_id: assistant.id, inspected_embeddings: inspected, next_after_id: last_id,
                     same_question: exact.map { |row| { faq_ids: row.fetch('ids').split(',').map(&:to_i),
                                                       different_answers: row.fetch('answer_versions') } },
                     semantic_review: pairs.values.sort_by { |v| -v[:cosine] } })
