-- v2.0.5: make generated phone normalization collation-deterministic for clean restores.
-- The formula is semantically identical to v2.0.0; every text operand now uses utf8mb4_unicode_ci.
ALTER TABLE users
    MODIFY COLUMN phone_normalized VARCHAR(20) GENERATED ALWAYS AS (
        NULLIF(
            REPLACE(
              REPLACE(
                REPLACE(
                  REPLACE(
                    REPLACE(
                      REPLACE(
                        REPLACE(
                          REPLACE(
                            REPLACE(
                              REPLACE(
                                REPLACE(
                                  CONVERT(TRIM(phone) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                                  _utf8mb4' ' COLLATE utf8mb4_unicode_ci,
                                  _utf8mb4'' COLLATE utf8mb4_unicode_ci
                                ),
                                CONVERT(CHAR(9) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                                _utf8mb4'' COLLATE utf8mb4_unicode_ci
                              ),
                              CONVERT(CHAR(10) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                              _utf8mb4'' COLLATE utf8mb4_unicode_ci
                            ),
                            CONVERT(CHAR(11) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                            _utf8mb4'' COLLATE utf8mb4_unicode_ci
                          ),
                          CONVERT(CHAR(12) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                          _utf8mb4'' COLLATE utf8mb4_unicode_ci
                        ),
                        CONVERT(CHAR(13) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        _utf8mb4'' COLLATE utf8mb4_unicode_ci
                      ),
                      _utf8mb4'(' COLLATE utf8mb4_unicode_ci,
                      _utf8mb4'' COLLATE utf8mb4_unicode_ci
                    ),
                    _utf8mb4')' COLLATE utf8mb4_unicode_ci,
                    _utf8mb4'' COLLATE utf8mb4_unicode_ci
                  ),
                  _utf8mb4'.' COLLATE utf8mb4_unicode_ci,
                  _utf8mb4'' COLLATE utf8mb4_unicode_ci
                ),
                _utf8mb4'-' COLLATE utf8mb4_unicode_ci,
                _utf8mb4'' COLLATE utf8mb4_unicode_ci
              ),
              _utf8mb4'+20' COLLATE utf8mb4_unicode_ci,
              _utf8mb4'0' COLLATE utf8mb4_unicode_ci
            ),
            _utf8mb4'' COLLATE utf8mb4_unicode_ci
        )
    ) STORED;
