CREATE EXTENSION IF NOT EXISTS timescaledb;

CREATE ROLE "wallet.wallet" LOGIN PASSWORD 'password';

create table assets(
    assetid varchar(32) not null primary key,
    name varchar(64) not null,
    icon_url varchar(255) not null,
    experimental boolean not null default FALSE
);

GRANT SELECT ON assets TO "wallet.wallet";

create table wallet_balances(
    uid bigint not null,
    assetid varchar(32) not null,
    total decimal(65,32) not null,
    locked decimal(65,32) not null default 0,
    
    foreign key(assetid) references assets(assetid)
);

GRANT SELECT, UPDATE, INSERT ON wallet_balances TO "wallet.wallet";

create table wallet_locks(
    lockid bigserial not null primary key,
    type varchar(32) not null,
    uid bigint not null,
    assetid varchar(32) not null,
    amount decimal(65, 32) not null,
    
    foreign key(assetid) references assets(assetid)
);

GRANT SELECT, INSERT, DELETE ON wallet_balances TO "wallet.wallet";

create table wallet_log(
    time timestamptz not null default current_timestamp,
    operation varchar(64) not null,
    lockid bigint default null,
    uid bigint not null,
    assetid varchar(32) not null,
    amount decimal(65, 32) not null,
    reason varchar(64) not null,
    context varchar(255) not null,
    
    foreign key(assetid) references assets(assetid)
);
SELECT create_hypertable('wallet_log', 'time');
SELECT add_retention_policy('wallet_log', INTERVAL '2 years');

GRANT INSERT ON wallet_log TO "wallet.wallet";