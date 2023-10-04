CREATE EXTENSION IF NOT EXISTS timescaledb;

CREATE ROLE "wallet.wallet" LOGIN PASSWORD 'password';

create table assets(
    assetid varchar(32) not null primary key,
    name varchar(64) not null,
    icon_url varchar(255) not null,
    default_prec int not null,
    enabled boolean not null,
    min_deposit decimal(65, 32) not null default 0,
    min_withdrawal decimal(65, 32) not null default 0
);

GRANT SELECT ON assets TO "wallet.wallet";

create table networks(
    netid varchar(32) not null primary key,
    description varchar(64) not null,
    native_assetid varchar(32) not null,
    confirms_target int not null,
    enabled boolean not null,
    memo_name varchar(32) default null,
    native_qr_format varchar(255) default null,
    token_qr_format varchar(255) default null,
    deposit_warning text default null,
    withdrawal_warning text default null,
    block_deposits_msg text default null,
    block_withdrawals_msg text default null,
    last_ping timestamptz not null default to_timestamp(0),
    
    foreign key(assetid) references assets(assetid)
);

GRANT SELECT ON networks TO "wallet.wallet";

create table asset_network(
    assetid varchar(32) not null,
    netid varchar(32) not null,
    prec int not null,
    wd_fee_base decimal(65,32) not null,
    enabled boolean not null,
    contract varchar(255) default null,
    deposit_warning text default null,
    withdrawal_warning text default null,
    block_deposits_msg text default null,
    block_withdrawals_msg text default null,
    min_deposit decimal(65, 32) not null default 0,
    min_withdrawal decimal(65, 32) not null default 0,
    wd_fee_min decimal(65,32) not null default 1000000,
    wd_fee_max decimal(65,32) not null default 1000000,
    
    foreign key(assetid) references assets(assetid),
    foreign key(netid) references networks(netid)
);

GRANT SELECT ON asset_network TO "wallet.wallet";

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

GRANT SELECT, INSERT, DELETE ON wallet_locks TO "wallet.wallet";
GRANT SELECT, USAGE ON wallet_locks_lockid_seq TO "wallet.wallet";

create table wallet_log(
    time timestamptz not null default current_timestamp,
    operation varchar(64) not null,
    lockid bigint default null,
    uid bigint not null,
    assetid varchar(32) not null,
    amount decimal(65, 32) not null,
    reason varchar(64) not null,
    context varchar(255) default null,
    
    foreign key(assetid) references assets(assetid)
);
SELECT create_hypertable('wallet_log', 'time');
SELECT add_retention_policy('wallet_log', INTERVAL '2 years');

GRANT INSERT ON wallet_log TO "wallet.wallet";

create table deposit_addr(
    addrid bigserial not null primary key,
    netid varchar(32) not null,
    address varchar(255) not null,
    memo varchar(255) default null,
    uid bigint default null,
    
    foreign key(netid) references networks(netid)
);

GRANT SELECT, INSERT, UPDATE ON deposit_addr TO "wallet.wallet";
