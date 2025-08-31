from typing import Any, Dict, List, Optional
import base64
import re

from pydantic import BaseModel, Field, field_validator, constr


class Party(BaseModel):
    xpub: constr(strip_whitespace=True, pattern=r'^[A-Za-z0-9]+$')


OrderID = constr(pattern=r'^[A-Za-z0-9_-]{1,32}$')


class CreateOrderReq(BaseModel):
    order_id: OrderID
    buyer: Party
    seller: Party
    escrow: Party
    index: Optional[int] = Field(None, ge=0)
    min_conf: int = Field(2, ge=0, le=100)
    amount_sat: int = Field(..., ge=0)


class CreateOrderRes(BaseModel):
    escrow_address: str
    descriptor: str
    watch_id: str


class StatusRes(BaseModel):
    funding: Optional[Dict[str, Any]] = None
    state: str
    deadline_ts: Optional[int] = None
    fee_est_sat: Optional[int] = None


class PSBTBuildReq(BaseModel):
    order_id: OrderID
    outputs: Dict[str, int]
    rbf: bool = True
    target_conf: int = Field(3, ge=1, le=100)

    @field_validator('outputs')
    def _check_outputs(cls, v):
        addr_re = re.compile(r'^(bc1|tb1)[0-9ac-hj-np-z]{8,87}$')
        for addr, amt in v.items():
            if not addr_re.match(addr):
                raise ValueError('invalid address')
            if not isinstance(amt, int) or amt <= 0 or amt > 2100000000000000:
                raise ValueError('invalid amount')
        return v


class PSBTRefundReq(BaseModel):
    order_id: OrderID
    address: constr(strip_whitespace=True, pattern=r'^(bc1|tb1)[0-9ac-hj-np-z]{8,87}$')
    rbf: bool = True
    target_conf: int = Field(3, ge=1, le=100)


class PayoutQuoteReq(BaseModel):
    address: constr(strip_whitespace=True, pattern=r'^(bc1|tb1)[0-9ac-hj-np-z]{8,87}$')
    rbf: bool = True
    target_conf: int = Field(3, ge=1, le=100)


class PayoutQuoteRes(BaseModel):
    fee_sat: int


class PSBTRes(BaseModel):
    psbt: str


class MergeReq(BaseModel):
    order_id: Optional[OrderID] = None
    partials: List[str]

    @field_validator('partials')
    def _check_part(cls, v):
        for item in v:
            try:
                base64.b64decode(item, validate=True)
            except Exception:
                raise ValueError('invalid psbt fragment')
        return v


class FinalizeReq(BaseModel):
    order_id: Optional[OrderID] = None
    psbt: str
    state: str = Field("completed", pattern=r'^(completed|refunded|dispute)$')


class BroadcastReq(BaseModel):
    hex: str
    order_id: Optional[OrderID] = None
    state: str = Field("completed", pattern=r'^(completed|refunded|dispute)$')


class BumpFeeReq(BaseModel):
    order_id: OrderID
    target_conf: int = Field(..., ge=1, le=100)


class DecodeReq(BaseModel):
    psbt: str


class DecodeRes(BaseModel):
    sign_count: int
    outputs: Dict[str, int]
    fee_sat: int
